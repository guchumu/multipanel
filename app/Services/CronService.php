<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ServerRepository;
use App\Services\Notifications\AdminCriticalAlertService;
use App\Services\Notifications\AdminDigestService;
use App\Services\Notifications\ExpiryNotificationService;
use Core\Database;
use Core\Updater;

/**
 * Tareas programadas compartidas por CLI (cron/run.php) y endpoint HTTP.
 */
final class CronService
{
    /** @return array<int, string> */
    public static function tasks(): array
    {
        return ['all', 'sync', 'automation', 'billing', 'backup', 'jobs', 'gdpr', 'cleanup', 'expiry', 'digest', 'migrate', 'health', 'streams'];
    }

    /**
     * @return array<string, array{title: string, description: string, schedule: string}>
     */
    public static function catalog(): array
    {
        return [
            'all' => [
                'title' => 'Todas',
                'description' => 'Ejecuta migrate + billing + sync + automation + backup (solo si toca por intervalo) + jobs + expiry + digest + streams + gdpr + cleanup.',
                'schedule' => 'Cada 5–15 minutos',
            ],
            'sync' => [
                'title' => 'Sincronizar servidores',
                'description' => 'Consulta Plex/Jellyfin: estado, bibliotecas, usuarios/membresía y sesiones. FAIL → alerta admin (con tipo Plex/Jellyfin).',
                'schedule' => 'Cada 5–15 minutos',
            ],
            'automation' => [
                'title' => 'Automatizaciones',
                'description' => 'Reglas activas; servidor caído: Telegram / email / WhatsApp (diagnóstico + tipo Plex/Jellyfin + escalado 0/5/15/30 min).',
                'schedule' => 'Cada 5–15 minutos',
            ],
            'expiry' => [
                'title' => 'Avisos de caducidad',
                'description' => 'Envía plantillas Telegram según días restantes (solo ~09:00 Europe/Madrid; fuera de hora no marca enviado). Puede desactivar caducados.',
                'schedule' => 'Incluido en all; solo envía a las 09:00 Madrid',
            ],
            'digest' => [
                'title' => 'Resumen diario admin',
                'description' => 'Caducidades hoy/semana, peticiones pendientes, servidores y overdue. Telegram y/o WhatsApp según toggles; una vez al día en la misma hora que caducidades.',
                'schedule' => 'Incluido en all; solo ~09:00 Madrid',
            ],
            'billing' => [
                'title' => 'Facturación',
                'description' => 'Marca suscripciones vencidas como past_due y lista atrasos.',
                'schedule' => '1 vez al día',
            ],
            'backup' => [
                'title' => 'Backup',
                'description' => 'Copia de seguridad cada ~6h (incluido en all con gate por último backup). Retención ~28 archivos ≈ 7 días. Fallo → alerta admin. La tarea backup fuerza una copia.',
                'schedule' => 'Cada 6 horas (gate en all)',
            ],
            'jobs' => [
                'title' => 'Cola de trabajos',
                'description' => 'Procesa trabajos asíncronos pendientes en la tabla jobs.',
                'schedule' => 'Cada 5 minutos',
            ],
            'gdpr' => [
                'title' => 'RGPD',
                'description' => 'Procesa solicitudes de borrado/exportación pendientes.',
                'schedule' => '1 vez al día',
            ],
            'cleanup' => [
                'title' => 'Limpieza',
                'description' => 'Borra sesiones caducadas, stats antiguas y notificaciones enviadas viejas.',
                'schedule' => '1 vez al día',
            ],
            'migrate' => [
                'title' => 'Migraciones',
                'description' => 'Aplica migraciones SQL pendientes (database/migrations).',
                'schedule' => 'Tras cada deploy (o incluido en all)',
            ],
            'health' => [
                'title' => 'Health check',
                'description' => 'Comprueba que la app responde (útil para monitor externo).',
                'schedule' => 'Cada 1–5 minutos (monitor)',
            ],
            'streams' => [
                'title' => 'Límite de streams',
                'description' => 'Detecta excesos de streams, los registra en Incumplimientos y corta solo si la aplicación automática está activa. Nuevos → alerta admin.',
                'schedule' => 'Cada 5–15 minutos (o con En directo abierto)',
            ],
        ];
    }

    /** @return array{ok: bool, task: string, lines: array<int, string>} */
    public function run(string $task, int $tenantId = 1): array
    {
        $task = trim($task) !== '' ? trim($task) : 'all';
        if (!in_array($task, self::tasks(), true)) {
            return ['ok' => false, 'task' => $task, 'lines' => ["Unknown task: {$task}"]];
        }

        $lines = [];
        $capture = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        try {
            match ($task) {
                'sync' => $this->runServerSync($tenantId, $capture),
                'automation' => $this->runAutomation($tenantId, $capture),
                'billing' => $this->runBilling($tenantId, $capture),
                'backup' => $this->runBackup($tenantId, $capture, true),
                'jobs' => $this->runJobs($capture),
                'gdpr' => $this->runGdpr($capture),
                'cleanup' => $this->runCleanup($capture),
                'expiry' => $this->runExpiryNotifications($tenantId, $capture),
                'digest' => $this->runAdminDigest($tenantId, $capture),
                'migrate' => $this->runMigrations($tenantId, $capture),
                'health' => $capture('Health OK'),
                'streams' => $this->runStreamLimits($tenantId, $capture),
                'all' => $this->runAll($tenantId, $capture),
            };
        } catch (\Throwable $e) {
            $capture('FATAL: ' . $e->getMessage());
            $this->logAlertResult(
                $capture,
                'cron',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, $task, $e->getMessage())
            );
        }

        return ['ok' => true, 'task' => $task, 'lines' => $lines];
    }

    /** @param callable(string): void $out */
    private function runAll(int $tenantId, callable $out): void
    {
        $this->runMigrations($tenantId, $out);
        $this->runBilling($tenantId, $out);
        $this->runServerSync($tenantId, $out);
        $this->runAutomation($tenantId, $out);
        $this->runBackup($tenantId, $out, false);
        $this->runJobs($out);
        $this->runExpiryNotifications($tenantId, $out);
        $this->runAdminDigest($tenantId, $out);
        $this->runStreamLimits($tenantId, $out);
        $this->runGdpr($out);
        $this->runCleanup($out);
    }

    /** @param callable(string): void $out */
    private function runStreamLimits(int $tenantId, callable $out): void
    {
        $out('Checking concurrent stream limits...');
        try {
            $stats = (new ConcurrentStreamLimitService())->runForTenant($tenantId);
            $out(sprintf(
                '  Checked sessions: %d, killed: %d, violations logged: %d',
                $stats['checked'],
                $stats['killed'],
                $stats['violations']
            ));
        } catch (\Throwable $e) {
            $out('  Stream limits failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'streams',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'streams', $e->getMessage())
            );
        }
    }

    /** @param callable(string): void $out */
    private function runMigrations(int $tenantId, callable $out): void
    {
        $out('Running pending database migrations...');
        try {
            $results = (new Updater())->runMigrations();
            if ($results === []) {
                $out('  No pending migrations.');
                return;
            }
            foreach ($results as $name => $status) {
                $out("  {$name}: {$status}");
            }
        } catch (\Throwable $e) {
            $out('  Migrations failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'migrate',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'migrate', $e->getMessage())
            );
        }
    }

    /** @param callable(string): void $out */
    private function runExpiryNotifications(int $tenantId, callable $out): void
    {
        $out('Sending expiry notifications...');
        try {
            $stats = (new ExpiryNotificationService())->run($tenantId);
            if (!empty($stats['deferred'])) {
                $sched = (new AlertSettingsService())->expiryNotifySchedule($tenantId);
                $hh = str_pad((string) $sched['hour'], 2, '0', STR_PAD_LEFT);
                $out("  Deferred: skipped until {$hh}:00 {$sched['timezone']} (no se marca como enviado).");
                return;
            }
            $out(sprintf(
                '  Checked: %d, sent: %d, skipped: %d, errors: %d, deactivated: %d',
                $stats['checked'],
                $stats['sent'],
                $stats['skipped'],
                $stats['errors'],
                $stats['deactivated']
            ));
            if ((int) ($stats['errors'] ?? 0) > 0) {
                $this->logAlertResult(
                    $out,
                    'expiry',
                    (new AdminCriticalAlertService())->notifyCronFailure(
                        $tenantId,
                        'expiry',
                        (string) ($stats['errors'] ?? 0) . ' errores enviando avisos de caducidad'
                    )
                );
            }
        } catch (\Throwable $e) {
            $out('  Expiry notifications failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'expiry',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'expiry', $e->getMessage())
            );
        }
    }

    /** @param callable(string): void $out */
    private function runAdminDigest(int $tenantId, callable $out): void
    {
        $out('Sending admin daily digest...');
        try {
            $stats = (new AdminDigestService())->run($tenantId);
            if (!empty($stats['deferred'])) {
                $sched = (new AlertSettingsService())->expiryNotifySchedule($tenantId);
                $hh = str_pad((string) $sched['hour'], 2, '0', STR_PAD_LEFT);
                $out("  Deferred: skipped until {$hh}:00 {$sched['timezone']}.");
                return;
            }
            if (!empty($stats['already_sent'])) {
                $out('  Already sent today.');
                return;
            }
            if (!empty($stats['skipped'])) {
                $out('  Skipped: digest channels disabled.');
                return;
            }
            if (!empty($stats['error'])) {
                $out('  Digest failed: ' . $stats['error']);
                $this->logAlertResult(
                    $out,
                    'digest',
                    (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'digest', (string) $stats['error'])
                );
                return;
            }
            $channels = isset($stats['channels']) && is_array($stats['channels'])
                ? implode(', ', $stats['channels'])
                : '—';
            $out('  Sent: ' . (int) ($stats['sent'] ?? 0) . " ({$channels})");
        } catch (\Throwable $e) {
            $out('  Admin digest failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'digest',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'digest', $e->getMessage())
            );
        }
    }

    /** @param callable(string): void $out */
    private function runServerSync(int $tenantId, callable $out): void
    {
        $out('Syncing servers...');
        $sync = new ServerSyncService();
        $repo = new ServerRepository();
        $failedNames = [];

        foreach ($repo->allByTenant($tenantId) as $server) {
            $label = $server->displayLabel();
            $result = $sync->sync($server);
            $out('  Server ' . $label . ': ' . ($result ? 'OK' : 'FAIL'));
            if (!$result) {
                $failedNames[] = $label;
            }
        }

        if ($failedNames !== []) {
            $alert = (new AdminCriticalAlertService())->notifySyncFailures($tenantId, $failedNames);
            $this->logAlertResult($out, 'sync FAIL (' . implode(', ', $failedNames) . ')', $alert);
        }
    }

    /** @param callable(string): void $out */
    private function runAutomation(int $tenantId, callable $out): void
    {
        $out('Running automation engine...');
        try {
            $result = (new AutomationEngine())->runAllWithStats($tenantId);
            $out('  Rules executed: ' . (int) ($result['rules_executed'] ?? 0));
            $down = $result['server_down'] ?? ['alerted' => 0, 'skipped' => 0, 'details' => []];
            $out(sprintf(
                '  Server-down alerts: alerted=%d skipped=%d cleared=%d',
                (int) ($down['alerted'] ?? 0),
                (int) ($down['skipped'] ?? 0),
                (int) ($down['cleared'] ?? 0)
            ));
            foreach ($down['details'] ?? [] as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $server = (string) ($detail['server'] ?? '?');
                $res = (string) ($detail['result'] ?? '');
                $reason = (string) ($detail['reason'] ?? '');
                if ($res === 'alerted') {
                    $channels = isset($detail['channels']) && is_array($detail['channels'])
                        ? implode(',', $detail['channels'])
                        : '';
                    $out("  Server {$server} offline — alert sent via {$channels}");
                } else {
                    $out("  Server {$server} offline — alert skipped: {$reason}");
                }
            }
        } catch (\Throwable $e) {
            $out('  Automation failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'automation',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'automation', $e->getMessage())
            );
        }
    }

    /** @param callable(string): void $out */
    private function runBilling(int $tenantId, callable $out): void
    {
        $out('Processing billing...');
        try {
            $billing = new BillingService();
            $pastDue = $billing->markPastDue($tenantId);
            $out("  Subscriptions marked past_due: {$pastDue}");
            $overdue = $billing->getOverdueSubscriptions($tenantId);
            $out('  Overdue subscriptions: ' . count($overdue));
        } catch (\Throwable $e) {
            $out('  Billing failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'billing',
                (new AdminCriticalAlertService())->notifyCronFailure($tenantId, 'billing', $e->getMessage())
            );
        }
    }

    /**
     * @param callable(string): void $out
     * @param bool $force Si true (tarea `backup`), crea siempre; si false (desde `all`), solo si pasó el intervalo.
     */
    private function runBackup(int $tenantId, callable $out, bool $force = false): void
    {
        $service = new BackupService();
        $hours = $service->intervalHours();
        $out($force ? 'Creating backup (forced)...' : "Checking backup schedule (every {$hours}h)...");

        try {
            if (!$force && !$service->isDue($tenantId)) {
                $out("  Skipped: último backup reciente; próximo como mínimo cada {$hours}h");
                $pruned = $service->prune($tenantId);
                if ($pruned > 0) {
                    $out("  Old backups pruned: {$pruned}");
                }

                return;
            }

            $result = $service->create($tenantId);
            if ($result) {
                $out('  Backup created: ' . ($result['filename'] ?? 'ok'));
                $pruned = $service->prune($tenantId);
                if ($pruned > 0) {
                    $out("  Old backups pruned: {$pruned}");
                }
            } else {
                $out('  Backup failed');
                $this->logAlertResult(
                    $out,
                    'backup',
                    (new AdminCriticalAlertService())->notifyBackupFailure($tenantId)
                );
            }
        } catch (\Throwable $e) {
            $out('  Backup failed: ' . $e->getMessage());
            $this->logAlertResult(
                $out,
                'backup',
                (new AdminCriticalAlertService())->notifyBackupFailure($tenantId, $e->getMessage())
            );
        }
    }

    /** @param callable(string): void $out */
    private function runJobs(callable $out): void
    {
        $out('Processing job queue...');
        $count = (new JobProcessor())->process();
        $out("  Jobs processed: {$count}");
    }

    /** @param callable(string): void $out */
    private function runGdpr(callable $out): void
    {
        $out('Processing GDPR requests...');
        $count = (new GdprService())->processPending();
        $out("  GDPR requests processed: {$count}");
    }

    /** @param callable(string): void $out */
    private function runCleanup(callable $out): void
    {
        $out('Cleaning up...');
        Database::getInstance()->query('DELETE FROM user_sessions WHERE expires_at < NOW()');
        Database::getInstance()->query('DELETE FROM server_stats WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
        Database::getInstance()->query("DELETE FROM notifications WHERE status = 'sent' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $out('  Cleanup complete.');
    }

    /**
     * @param callable(string): void $out
     * @param array{ok: bool, skipped: bool, reason: string, channels: array<int, string>} $result
     */
    private function logAlertResult(callable $out, string $label, array $result): void
    {
        if (!empty($result['ok'])) {
            $via = implode(',', $result['channels'] ?? []) ?: '—';
            $out("  Alert [{$label}] sent via {$via}");
            return;
        }
        $out('  Alert [' . $label . '] skipped: ' . (string) ($result['reason'] ?? 'unknown'));
    }
}
