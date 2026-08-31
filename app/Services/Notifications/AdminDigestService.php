<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Repositories\MediaUserRepository;
use App\Repositories\PeticionesRepository;
use App\Services\AlertSettingsService;
use App\Services\BillingService;
use App\Services\ConcurrentStreamLimitService;
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
    private const TOP_OFFLINE = 4;
    private const TOP_VIOLATORS = 3;

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
                'RESUMEN DIARIO',
                $message,
                $channels,
                [
                    'level' => 'info',
                    'digest_date' => $today,
                    'whatsapp_kind' => 'digest',
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
     * @return array<string, mixed>
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

            $label = $this->userLabel($user);

            if ($daysLeft === 0) {
                $todayEmails[] = $label;
            }
            if ($daysLeft <= 7) {
                $weekEmails[] = $label . " ({$daysLeft}d)";
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
        $offlineNames = [];
        $syncErrors = [];
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT name, type, status, last_error, last_sync_at
                 FROM servers
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 ORDER BY name ASC",
                [$tenantId]
            );
            foreach ($rows as $row) {
                $serversTotal++;
                $status = (string) ($row['status'] ?? '');
                $name = trim((string) ($row['name'] ?? '')) ?: 'Servidor';
                $rawType = trim((string) ($row['type'] ?? ''));
                $type = match ($rawType) {
                    'plex' => 'Plex',
                    'jellyfin' => 'Jellyfin',
                    '' => 'Desconocido',
                    default => ucfirst($rawType),
                };
                $label = $name . ' (' . $type . ')';
                if ($status === 'online') {
                    $serversOnline++;
                } elseif ($status === 'offline') {
                    $serversOffline++;
                    if (count($offlineNames) < self::TOP_OFFLINE) {
                        $offlineNames[] = $label;
                    }
                }
                $err = trim((string) ($row['last_error'] ?? ''));
                if ($err !== '' && count($syncErrors) < self::TOP_OFFLINE) {
                    $syncErrors[] = $label . ': ' . mb_substr($err, 0, 60);
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

        $violations24h = null;
        $topAbusers = [];
        try {
            $since = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
            $countRow = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS c FROM stream_limit_violations
                 WHERE tenant_id = ? AND created_at >= ?',
                [$tenantId, $since]
            );
            $violations24h = (int) ($countRow['c'] ?? 0);

            $abuserRows = Database::getInstance()->fetchAll(
                'SELECT v.media_user_id, COUNT(*) AS c,
                        MAX(COALESCE(NULLIF(mu.email, \'\'), NULLIF(mu.username, \'\'), CONCAT(\'#\', v.media_user_id))) AS label
                 FROM stream_limit_violations v
                 LEFT JOIN media_users mu ON mu.id = v.media_user_id
                 WHERE v.tenant_id = ? AND v.created_at >= ?
                 GROUP BY v.media_user_id
                 ORDER BY c DESC
                 LIMIT ' . self::TOP_VIOLATORS,
                [$tenantId, $since]
            );
            foreach ($abuserRows as $r) {
                $topAbusers[] = trim((string) ($r['label'] ?? '#')) . '×' . (int) ($r['c'] ?? 0);
            }
        } catch (\Throwable) {
            // Tabla puede no existir aún
            try {
                $recent = (new ConcurrentStreamLimitService())->listViolations($tenantId, 50);
                $violations24h = count($recent);
            } catch (\Throwable) {
                $violations24h = null;
            }
        }

        $openTickets = null;
        try {
            $ticketRow = Database::getInstance()->fetchOne(
                "SELECT COUNT(*) AS c FROM tickets
                 WHERE tenant_id = ? AND status IN ('open', 'in_progress', 'waiting')",
                [$tenantId]
            );
            $openTickets = (int) ($ticketRow['c'] ?? 0);
        } catch (\Throwable) {
            $openTickets = null;
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
            'offline_names' => $offlineNames,
            'sync_errors' => $syncErrors,
            'overdue' => $overdue,
            'violations_24h' => $violations24h,
            'top_abusers' => $topAbusers,
            'open_tickets' => $openTickets,
        ];
    }

    private function userLabel(object $user): string
    {
        $label = trim((string) ($user->email ?? ''));
        if ($label === '') {
            $label = trim((string) ($user->display_name ?? ''));
        }
        if ($label === '') {
            $label = trim((string) ($user->username ?? ''));
        }
        if ($label === '') {
            $label = '#' . (int) ($user->id ?? 0);
        }

        return $label;
    }

    /** @param array<string, mixed> $p */
    private function formatMessage(array $p, string $dateYmd): string
    {
        $caducidades = [
            AdminMessageFormat::label('Caducidades hoy', (string) (int) $p['today_count']),
        ];
        if ($p['today_emails'] !== []) {
            $caducidades[] = '  ' . implode(', ', $p['today_emails']);
            if ((int) $p['today_count'] > count($p['today_emails'])) {
                $caducidades[] = '  …';
            }
        }
        $caducidades[] = AdminMessageFormat::label('Caducidades ≤7d', (string) (int) $p['week_count']);
        if ($p['week_emails'] !== [] && (int) $p['week_count'] !== (int) $p['today_count']) {
            $caducidades[] = '  ' . implode(', ', $p['week_emails']);
            if ((int) $p['week_count'] > count($p['week_emails'])) {
                $caducidades[] = '  …';
            }
        }

        $servidores = [
            AdminMessageFormat::label(
                'Servidores',
                sprintf(
                    '%d online / %d offline (total %d)',
                    (int) $p['servers_online'],
                    (int) $p['servers_offline'],
                    (int) $p['servers_total']
                )
            ),
        ];
        if (!empty($p['offline_names'])) {
            $servidores[] = AdminMessageFormat::label('Offline', implode(', ', $p['offline_names']));
        }
        if (!empty($p['sync_errors'])) {
            $servidores[] = AdminMessageFormat::label('Sync/err', implode(' | ', $p['sync_errors']));
        }

        $extra = [];
        if ($p['violations_24h'] !== null) {
            $extra[] = AdminMessageFormat::label('Violaciones streams (24h)', (string) (int) $p['violations_24h']);
            if (!empty($p['top_abusers'])) {
                $extra[] = AdminMessageFormat::label('Top', implode(', ', $p['top_abusers']));
            }
        }
        if ($p['open_tickets'] !== null) {
            $extra[] = AdminMessageFormat::label('Tickets abiertos', (string) (int) $p['open_tickets']);
        }
        if ($p['peticiones_pendientes'] === null) {
            $extra[] = AdminMessageFormat::label('Peticiones pendientes', 'n/d');
        } else {
            $extra[] = AdminMessageFormat::label('Peticiones pendientes', (string) (int) $p['peticiones_pendientes']);
        }
        if ($p['overdue'] !== null) {
            $extra[] = AdminMessageFormat::label('Suscripciones overdue', (string) (int) $p['overdue']);
        }

        $text = AdminMessageFormat::compose([
            AdminMessageFormat::title('Caducidades') . "\n" . implode("\n", $caducidades),
            AdminMessageFormat::title('Servidores') . "\n" . implode("\n", $servidores),
            $extra !== [] ? AdminMessageFormat::title('Actividad') . "\n" . implode("\n", $extra) : '',
        ]);
        // WhatsApp/Telegram: mantener mensaje manejable.
        if (mb_strlen($text) > 3500) {
            $text = mb_substr($text, 0, 3490) . "\n…";
        }

        return $text;
    }
}
