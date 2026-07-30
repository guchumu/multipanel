<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
use App\Services\Notifications\NotificationService;
use Core\Database;
use Core\Logger;

/**
 * Automation rule engine — evaluates conditions and executes actions.
 */
final class AutomationEngine
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private NotificationService $notifications = new NotificationService(),
        private AuditService $audit = new AuditService(),
    ) {
    }

    public function runAll(int $tenantId = 1): int
    {
        $rules = Database::getInstance()->fetchAll(
            'SELECT * FROM automation_rules WHERE tenant_id = ? AND is_active = 1 ORDER BY priority DESC',
            [$tenantId]
        );

        $executed = 0;
        foreach ($rules as $rule) {
            if ($this->runRule($rule)) {
                $executed++;
            }
        }

        $this->runBuiltInRules($tenantId);

        return $executed;
    }

    /** @param array<string, mixed> $rule */
    public function runRule(array $rule): bool
    {
        $conditions = json_decode($rule['conditions'] ?? '[]', true) ?: [];
        $actions = json_decode($rule['actions'] ?? '[]', true) ?: [];

        if (!$this->evaluateConditions($conditions, $rule)) {
            return false;
        }

        foreach ($actions as $action) {
            $this->executeAction($action, $rule);
        }

        Database::getInstance()->update('automation_rules', [
            'last_run_at' => date('Y-m-d H:i:s'),
            'run_count' => (int) $rule['run_count'] + 1,
        ], 'id = ?', [$rule['id']]);

        Logger::info('Automation rule executed', ['rule' => $rule['name']]);
        return true;
    }

    /** @param array<int, array<string, mixed>> $conditions */
    private function evaluateConditions(array $conditions, array $rule): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? null;

            $actual = match ($field) {
                'trigger' => $rule['trigger_event'],
                'hour' => (int) date('G'),
                'day_of_week' => (int) date('N'),
                default => null,
            };

            if (!$this->compare($actual, $operator, $value)) {
                return false;
            }
        }

        return true;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'equals', '=' => $actual == $expected,
            'not_equals', '!=' => $actual != $expected,
            'greater', '>' => $actual > $expected,
            'less', '<' => $actual < $expected,
            'contains' => is_string($actual) && str_contains($actual, (string) $expected),
            default => false,
        };
    }

    /** @param array<string, mixed> $action */
    private function executeAction(array $action, array $rule): void
    {
        $type = $action['type'] ?? '';
        $params = $action['params'] ?? [];

        match ($type) {
            'suspend_user' => $this->actionSuspendUser($params),
            'delete_user' => $this->actionDeleteUser($params),
            'activate_user' => $this->actionActivateUser($params),
            'notify' => $this->actionNotify($params),
            'extend_subscription' => $this->actionExtendSubscription($params),
            default => Logger::warning('Unknown automation action', ['type' => $type]),
        };
    }

    /** @param array<string, mixed> $params */
    private function actionSuspendUser(array $params): void
    {
        $daysOverdue = (int) ($params['days_overdue'] ?? 5);
        $users = Database::getInstance()->fetchAll(
            "SELECT mu.* FROM media_users mu
             JOIN subscriptions s ON s.media_user_id = mu.id
             WHERE s.status = 'past_due'
             AND s.ends_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             AND mu.status = 'active' AND mu.deleted_at IS NULL",
            [$daysOverdue]
        );

        foreach ($users as $row) {
            $user = new MediaUser($row);
            $user->status = 'suspended';
            $user->save();
            $this->audit->log('automation.suspend', 'media_user', (int) $user->id);
            $this->notifications->notify(
                'automation.suspend',
                'Usuario suspendido automáticamente',
                "Usuario {$user->username} suspendido por impago ({$daysOverdue} días).",
                ['telegram']
            );
        }
    }

    /** @param array<string, mixed> $params */
    private function actionDeleteUser(array $params): void
    {
        $daysOverdue = (int) ($params['days_overdue'] ?? 15);
        $users = Database::getInstance()->fetchAll(
            "SELECT * FROM media_users
             WHERE status = 'suspended'
             AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             AND deleted_at IS NULL",
            [$daysOverdue]
        );

        foreach ($users as $row) {
            $user = new MediaUser($row);
            $user->status = 'deleted';
            $user->deleted_at = date('Y-m-d H:i:s');
            $user->save();
            $this->audit->log('automation.delete', 'media_user', (int) $user->id);
        }
    }

    /** @param array<string, mixed> $params */
    private function actionActivateUser(array $params): void
    {
        $users = Database::getInstance()->fetchAll(
            "SELECT mu.* FROM media_users mu
             JOIN subscriptions s ON s.media_user_id = mu.id
             WHERE s.status = 'active' AND mu.status = 'suspended' AND mu.deleted_at IS NULL"
        );

        foreach ($users as $row) {
            $user = new MediaUser($row);
            $user->status = 'active';
            $user->save();
            $this->audit->log('automation.activate', 'media_user', (int) $user->id);
            $this->notifications->notify(
                'automation.activate',
                'Usuario reactivado',
                "Usuario {$user->username} reactivado tras confirmar pago.",
                ['telegram', 'email']
            );
        }
    }

    /** @param array<string, mixed> $params */
    private function actionNotify(array $params): void
    {
        $this->notifications->notify(
            $params['event'] ?? 'automation',
            $params['title'] ?? 'Automatización',
            $params['message'] ?? '',
            $params['channels'] ?? ['telegram'],
            $params['data'] ?? []
        );
    }

    /** @param array<string, mixed> $params */
    private function actionExtendSubscription(array $params): void
    {
        $days = (int) ($params['days'] ?? 30);
        Database::getInstance()->query(
            "UPDATE subscriptions SET ends_at = DATE_ADD(COALESCE(ends_at, NOW()), INTERVAL ? DAY), status = 'active'
             WHERE status IN ('active', 'past_due')",
            [$days]
        );
    }

    private function runBuiltInRules(int $tenantId): void
    {
        // Expire users past expiration date
        $expired = Database::getInstance()->query(
            "UPDATE media_users SET status = 'expired'
             WHERE tenant_id = ? AND expires_at IS NOT NULL AND expires_at < NOW() AND status = 'active'",
            [$tenantId]
        );

        if ($expired->rowCount() > 0) {
            Logger::info('Built-in: expired users', ['count' => $expired->rowCount()]);
        }

        // Notify servers that went offline
        $offlineServers = Database::getInstance()->fetchAll(
            "SELECT name FROM servers WHERE tenant_id = ? AND status = 'offline' AND last_check_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            [$tenantId]
        );

        foreach ($offlineServers as $server) {
            $this->notifications->notifyServerDown($server['name']);
        }
    }
}
