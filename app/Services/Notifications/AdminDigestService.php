<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Repositories\MediaUserRepository;
use App\Repositories\PeticionesRepository;
use App\Services\AlertSettingsService;
use App\Services\BillingService;
use App\Services\Peticiones\PeticionesConfig;
use Core\Database;
use Core\Logger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Resumen diario admin (~09:00 Europe/Madrid, misma ventana que caducidades).
 * Telegram y/o WhatsApp según toggles; una vez por día calendario.
 */
final class AdminDigestService
{
    private const TOP_EMAILS = 5;

    public function __construct(
        private NotificationService $notifications = new NotificationService(),
        private AlertSettingsService $alerts = new AlertSettingsService(),
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private BillingService $billing = new BillingService(),
    ) {
    }

    /**
     * @return array{
     *   sent: int,
     *   deferred?: int,
     *   skipped?: int,
     *   already_sent?: int,
     *   channels?: array<int, string>,
     *   error?: string
     * }
     */
    public function run(int $tenantId = 1): array
    {
        $schedule = $this->alerts->expiryNotifySchedule($tenantId);
        if (!$this->alerts->isWithinExpiryNotifyWindow($schedule, $tenantId)) {
            return ['sent' => 0, 'deferred' => 1];
        }

        $channels = $this->notifications->adminDigestChannels($tenantId);
        if ($channels === []) {
            return ['sent' => 0, 'skipped' => 1];
        }

        try {
            $tz = new DateTimeZone($schedule['timezone']);
        } catch (\Throwable) {
            $tz = new DateTimeZone('Europe/Madrid');
        }
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        if ($this->alerts->digestLastSentDate($tenantId) === $today) {
            return ['sent' => 0, 'already_sent' => 1];
        }

        try {
            $payload = $this->buildPayload($tenantId, $tz);
            $message = $this->formatMessage($payload, $today);

            $results = $this->notifications->notify(
                'admin.digest',
                'Resumen diario',
                $message,
                $channels,
                [
                    'level' => 'info',
                    'digest_date' => $today,
                    'tenant_id' => $tenantId,
                ],
                null,
                $tenantId
            );

            $anySent = in_array(true, $results, true);
            if ($anySent) {
                $this->alerts->markDigestSent($today, $tenantId);
            }

            Logger::info('Admin digest dispatched', [
                'tenant_id' => $tenantId,
                'date' => $today,
                'channels' => $channels,
                'results' => $results,
            ]);

            return [
                'sent' => $anySent ? 1 : 0,
                'channels' => $channels,
            ];
        } catch (\Throwable $e) {
            Logger::warning('Admin digest failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{
     *   today_count: int,
     *   week_count: int,
     *   today_emails: array<int, string>,
     *   week_emails: array<int, string>,
     *   peticiones_pendientes: int|null,
     *   servers_online: int,
     *   servers_offline: int,
     *   servers_total: int,
     *   overdue: int|null
     * }
     */
    private function buildPayload(int $tenantId, DateTimeZone $tz): array
    {
        $expiring = $this->mediaUsers->findExpiringSoon($tenantId, 7, null, false);
        $todayEmails = [];
        $weekEmails = [];

        foreach ($expiring as $user) {
            $daysLeft = isset($user->days_left) ? (int) $user->days_left : null;
            if ($daysLeft === null && !empty($user->expires_at)) {
                $expiresDate = new DateTimeImmutable(substr((string) $user->expires_at, 0, 10), $tz);
                $today = new DateTimeImmutable('today', $tz);
                $daysLeft = (int) floor(($expiresDate->getTimestamp() - $today->getTimestamp()) / 86400);
            }
            if ($daysLeft === null || $daysLeft < 0) {
                continue;
            }

            $label = trim((string) ($user->email ?? ''));
            if ($label === '') {
                $label = trim((string) ($user->username ?? ''));
            }
            if ($label === '') {
                $label = '#' . (int) ($user->id ?? 0);
            }

            if ($daysLeft === 0) {
                $todayEmails[] = $label;
            }
            if ($daysLeft <= 7) {
                $weekEmails[] = $label;
            }
        }

        $peticiones = null;
        try {
            $cfg = PeticionesConfig::forTenant($tenantId);
            if (!empty($cfg['configured'])) {
                $peticiones = (int) ((new PeticionesRepository())->counts()['pendientes'] ?? 0);
            }
        } catch (\Throwable) {
            $peticiones = null;
        }

        $serversOnline = 0;
        $serversOffline = 0;
        $serversTotal = 0;
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT status, COUNT(*) AS c FROM servers
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 GROUP BY status",
                [$tenantId]
            );
            foreach ($rows as $row) {
                $c = (int) ($row['c'] ?? 0);
                $serversTotal += $c;
                $status = (string) ($row['status'] ?? '');
                if ($status === 'online') {
                    $serversOnline += $c;
                } elseif ($status === 'offline') {
                    $serversOffline += $c;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        $overdue = null;
        try {
            $overdue = count($this->billing->getOverdueSubscriptions($tenantId));
        } catch (\Throwable) {
            $overdue = null;
        }

        return [
            'today_count' => count($todayEmails),
            'week_count' => count($weekEmails),
            'today_emails' => array_slice($todayEmails, 0, self::TOP_EMAILS),
            'week_emails' => array_slice($weekEmails, 0, self::TOP_EMAILS),
            'peticiones_pendientes' => $peticiones,
            'servers_online' => $serversOnline,
            'servers_offline' => $serversOffline,
            'servers_total' => $serversTotal,
            'overdue' => $overdue,
        ];
    }

    /** @param array<string, mixed> $p */
    private function formatMessage(array $p, string $dateYmd): string
    {
        $lines = [];
        $lines[] = "Resumen diario ({$dateYmd})";
        $lines[] = '';

        $lines[] = 'Caducidades hoy: ' . (int) $p['today_count'];
        if ($p['today_emails'] !== []) {
            $lines[] = '  · ' . implode(', ', $p['today_emails']);
            if ((int) $p['today_count'] > count($p['today_emails'])) {
                $lines[] = '  · …';
            }
        }

        $lines[] = 'Caducidades esta semana: ' . (int) $p['week_count'];
        if ($p['week_emails'] !== [] && (int) $p['week_count'] !== (int) $p['today_count']) {
            $lines[] = '  · ' . implode(', ', $p['week_emails']);
            if ((int) $p['week_count'] > count($p['week_emails'])) {
                $lines[] = '  · …';
            }
        }

        $lines[] = '';
        if ($p['peticiones_pendientes'] === null) {
            $lines[] = 'Peticiones pendientes: n/d';
        } else {
            $lines[] = 'Peticiones pendientes: ' . (int) $p['peticiones_pendientes'];
        }

        $lines[] = sprintf(
            'Servidores: %d online / %d offline (total %d)',
            (int) $p['servers_online'],
            (int) $p['servers_offline'],
            (int) $p['servers_total']
        );

        if ($p['overdue'] !== null) {
            $lines[] = 'Suscripciones overdue: ' . (int) $p['overdue'];
        }

        return implode("\n", $lines);
    }
}
