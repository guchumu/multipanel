<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\MediaUser;
use App\Services\AlertSettingsService;
use App\Services\NotificationTemplateService;
use Core\Database;
use Core\Logger;
use Core\Updater;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sends Telegram reminders before/after media user expiry.
 * Solo envía en la ventana horaria configurada (por defecto ~09:00 Europe/Madrid).
 */
final class ExpiryNotificationService
{
    public function __construct(
        private TelegramChannel $telegram = new TelegramChannel(),
        private NotificationTemplateService $templates = new NotificationTemplateService(),
        private AlertSettingsService $alertSettings = new AlertSettingsService(),
    ) {
    }

    /** @return array{sent: int, skipped: int, errors: int, checked: int, deactivated: int, deferred?: int} */
    public function run(int $tenantId = 1): array
    {
        if (!config('expiry_notifications.enabled', true)) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'checked' => 0, 'deactivated' => 0];
        }

        $schedule = $this->alertSettings->expiryNotifySchedule($tenantId);
        if (!$this->alertSettings->isWithinExpiryNotifyWindow($schedule, $tenantId)) {
            $tzLabel = $schedule['timezone'];
            $hour = str_pad((string) $schedule['hour'], 2, '0', STR_PAD_LEFT);
            Logger::info("Expiry notifications skipped until {$hour}:00 {$tzLabel} (no se marca como enviado)");
            return [
                'sent' => 0,
                'skipped' => 0,
                'errors' => 0,
                'checked' => 0,
                'deactivated' => 0,
                'deferred' => 1,
            ];
        }

        self::ensureExpiryNoticesTable();

        $milestones = $this->templates->getMilestones($tenantId);
        $messages = $this->templates->getExpiryMessages($tenantId);
        $title = (string) config('expiry_notifications.title', 'Aviso de caducidad');
        $notifyAdmin = (bool) config('expiry_notifications.notify_admin', true);
        $deactivateOnExpiry = (bool) config('expiry_notifications.deactivate_on_expiry', true);
        $tz = new DateTimeZone($schedule['timezone']);
        $today = new DateTimeImmutable('today', $tz);

        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'checked' => 0, 'deactivated' => 0];

        $rows = Database::getInstance()->fetchAll(
            'SELECT mu.*, s.name AS server_name
             FROM media_users mu
             LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
             WHERE mu.tenant_id = ?
               AND mu.deleted_at IS NULL
               AND mu.expires_at IS NOT NULL
               AND mu.status IN (\'active\', \'invited\')',
            [$tenantId]
        );

        foreach ($rows as $row) {
            $stats['checked']++;
            $user = new MediaUser($row);
            $expiresAt = trim((string) ($user->expires_at ?? ''));
            if ($expiresAt === '') {
                continue;
            }

            $expiresDate = new DateTimeImmutable(substr($expiresAt, 0, 10), $tz);
            $daysLeft = (int) floor(($expiresDate->getTimestamp() - $today->getTimestamp()) / 86400);

            if (!in_array($daysLeft, $milestones, true)) {
                continue;
            }

            $milestoneKey = (string) $daysLeft;
            if ($this->alreadySent((int) $user->id, $milestoneKey)) {
                continue;
            }

            $chatId = trim((string) ($user->telegram_chat_id ?? ''));
            if ($chatId === '') {
                $stats['skipped']++;
                Logger::debug('Expiry notice skipped: no Telegram chat ID', [
                    'media_user_id' => $user->id,
                    'username' => $user->username,
                    'milestone' => $milestoneKey,
                ]);
                continue;
            }

            $template = $messages[$daysLeft] ?? $messages[$milestoneKey] ?? null;
            if (!is_string($template) || trim($template) === '') {
                $stats['skipped']++;
                continue;
            }

            $body = $this->renderTemplate($template, $user, (string) ($row['server_name'] ?? ''), $daysLeft);
            $sent = $this->telegram->send($title, $body, [
                'chat_id' => $chatId,
                'media_user_id' => (int) $user->id,
                'tenant_id' => (int) ($user->tenant_id ?? 1),
                'message_type' => 'expiry_' . $milestoneKey,
                'log_message' => true,
                'user_message' => true,
            ]);

            if ($sent) {
                $this->recordSent((int) $user->id, $milestoneKey);
                $stats['sent']++;
                Logger::info('Expiry notice sent', [
                    'media_user_id' => $user->id,
                    'milestone' => $milestoneKey,
                ]);

                if ($notifyAdmin) {
                    $adminMsg = "✓ Notificación enviada a {$user->email} (días restantes: {$daysLeft})";
                    $this->telegram->send('Aviso caducidad', $adminMsg);
                }

                if ($deactivateOnExpiry && $daysLeft < 0 && in_array($user->status, ['active', 'invited'], true)) {
                    $user->status = 'expired';
                    $user->save();
                    $stats['deactivated']++;
                    Logger::info('Media user deactivated after expiry notice', [
                        'media_user_id' => $user->id,
                    ]);
                }
            } else {
                $stats['errors']++;
                if ($notifyAdmin) {
                    $this->telegram->send('Error aviso caducidad', "✗ Error enviando notificación a {$user->email}");
                }
            }
        }

        return $stats;
    }

    /**
     * Prefer Updater/migrations; fall back to CREATE IF NOT EXISTS when the table is still missing.
     */
    public static function ensureExpiryNoticesTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (self::tableExists('media_user_expiry_notices')) {
            $ensured = true;
            return;
        }

        try {
            (new Updater())->runMigrations();
        } catch (\Throwable) {
            // Fall through to direct CREATE.
        }

        if (self::tableExists('media_user_expiry_notices')) {
            $ensured = true;
            return;
        }

        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `media_user_expiry_notices` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `media_user_id` BIGINT UNSIGNED NOT NULL,
                    `milestone` VARCHAR(10) NOT NULL COMMENT \'Days before expiry: 10,5,3,2,1,0,-1\',
                    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_expiry_notice_user_milestone` (`media_user_id`, `milestone`),
                    CONSTRAINT `fk_expiry_notice_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            try {
                Database::getInstance()->pdo()->exec(
                    'CREATE TABLE IF NOT EXISTS `media_user_expiry_notices` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `media_user_id` BIGINT UNSIGNED NOT NULL,
                        `milestone` VARCHAR(10) NOT NULL,
                        `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uk_expiry_notice_user_milestone` (`media_user_id`, `milestone`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (\Throwable) {
                return;
            }
        }

        $ensured = true;
    }

    private static function tableExists(string $table): bool
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?',
                [$table]
            );

            return ((int) ($row['total'] ?? 0)) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function alreadySent(int $mediaUserId, string $milestone): bool
    {
        self::ensureExpiryNoticesTable();

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT id FROM media_user_expiry_notices WHERE media_user_id = ? AND milestone = ? LIMIT 1',
                [$mediaUserId, $milestone]
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function recordSent(int $mediaUserId, string $milestone): void
    {
        self::ensureExpiryNoticesTable();

        Database::getInstance()->insert('media_user_expiry_notices', [
            'media_user_id' => $mediaUserId,
            'milestone' => $milestone,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function renderTemplate(string $template, MediaUser $user, string $serverName, int $daysLeft): string
    {
        $expiresAt = (string) ($user->expires_at ?? '');
        $expiresDate = $expiresAt !== '' ? substr($expiresAt, 0, 10) : '';
        $endDateFormatted = $expiresDate !== ''
            ? (new DateTimeImmutable($expiresDate))->format('d/m/Y')
            : '';

        $replace = [
            '{username}' => (string) $user->username,
            '{email}' => (string) ($user->email ?? ''),
            '{display_name}' => (string) ($user->display_name ?: $user->username),
            '{expires_at}' => $expiresAt,
            '{expires_date}' => $expiresDate,
            '{end_date}' => $endDateFormatted,
            '{days}' => (string) abs($daysLeft),
            '{days_left}' => (string) $daysLeft,
            '{server_name}' => $serverName !== '' ? $serverName : '—',
        ];

        return str_replace(array_keys($replace), array_values($replace), $template);
    }
}
