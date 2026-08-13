<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ServerRepository;
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
        return ['all', 'sync', 'automation', 'billing', 'backup', 'jobs', 'gdpr', 'cleanup', 'expiry', 'migrate', 'health', 'streams'];
    }

    /**
     * @return array<string, array{title: string, description: string, schedule: string}>
     */
    public static function catalog(): array
    {
        return [
            'all' => [
                'title' => 'Todas',
                'description' => 'Ejecuta migrate + billing + sync + automation + backup + jobs + expiry + streams + gdpr + cleanup.',
                'schedule' => 'Cada 5–15 minutos',
            ],
            'sync' => [
                'title' => 'Sincronizar servidores',
                'description' => 'Consulta Plex/Jellyfin: estado, bibliotecas, usuarios/membresía y sesiones.',
                'schedule' => 'Cada 5–15 minutos',
            ],
            'automation' => [
                'title' => 'Automatizaciones',
                'description' => 'Reglas activas; servidor caído: Telegram + email + WhatsApp (CallMeBot opcional), con diagnóstico y escalado 5/15/30 min.',
                'schedule' => 'Cada 5–15 minutos',
            ],
            'expiry' => [
                'title' => 'Avisos de caducidad',
                'description' => 'Envía plantillas Telegram según días restantes (solo ~09:00 Europe/Madrid; fuera de hora no marca enviado). Puede desactivar caducados.',
                'schedule' => 'Incluido en all; solo envía a las 09:00 Madrid',
            ],
            'billing' => [
                'title' => 'Facturación',
                'description' => 'Marca suscripciones vencidas como past_due y lista atrasos.',
                'schedule' => '1 vez al día',
            ],
            'backup' => [
                'title' => 'Backup',
                'description' => 'Genera copia de seguridad y limpia backups antiguos según retención.',
                'schedule' => 'Diario de madrugada',
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
                'description' => 'Cuenta sesiones activas por usuario media y corta las que superan el límite.',
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

        match ($task) {
            'sync' => $this->runServerSync($tenantId, $capture),
            'automation' => $this->runAutomation($tenantId, $capture),
            'billing' => $this->runBilling($tenantId, $capture),
            'backup' => $this->runBackup($tenantId, $capture),
            'jobs' => $this->runJobs($capture),
            'gdpr' => $this->runGdpr($capture),
            'cleanup' => $this->runCleanup($capture),
            'expiry' => $this->runExpiryNotifications($tenantId, $capture),
            'migrate' => $this->runMigrations($capture),
            'health' => $capture('Health OK'),
            'streams' => $this->runStreamLimits($tenantId, $capture),
            'all' => $this->runAll($tenantId, $capture),
        };

        return ['ok' => true, 'task' => $task, 'lines' => $lines];
    }

    /** @param callable(string): void $out */
    private function runAll(int $tenantId, callable $out): void
    {
        $this->runMigrations($out);
        $this->runBilling($tenantId, $out);
        $this->runServerSync($tenantId, $out);
        $this->runAutomation($tenantId, $out);
        $this->runBackup($tenantId, $out);
        $this->runJobs($out);
        $this->runExpiryNotifications($tenantId, $out);
        $this->runStreamLimits($tenantId, $out);
        $this->runGdpr($out);
        $this->runCleanup($out);
    }

    /** @param callable(string): void $out */
    private function runStreamLimits(int $tenantId, callable $out): void
    {
        $out('Enforcing concurrent stream limits...');
        try {
            $stats = (new ConcurrentStreamLimitService())->runForTenant($tenantId);
            $out(sprintf(
                '  Checked sessions: %d, killed: %d, violations: %d',
                $stats['checked'],
                $stats['killed'],
                $stats['violations']
            ));
        } catch (\Throwable $e) {
            $out('  Stream limits failed: ' . $e->getMessage());
        }
    }

    /** @param callable(string): void $out */
    private function runMigrations(callable $out): void
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
        } catch (\Throwable $e) {
            $out('  Expiry notifications failed: ' . $e->getMessage());
        }
    }

    /** @param callable(string): void $out */
    private function runServerSync(int $tenantId, callable $out): void
    {
        $out('Syncing servers...');
        $sync = new ServerSyncService();
        $repo = new ServerRepository();

        foreach ($repo->allByTenant($tenantId) as $server) {
            $result = $sync->sync($server);
            $out('  Server ' . $server->name . ': ' . ($result ? 'OK' : 'FAIL'));
        }
    }

    /** @param callable(string): void $out */
    private function runAutomation(int $tenantId, callable $out): void
    {
        $out('Running automation engine...');
        $count = (new AutomationEngine())->runAll($tenantId);
        $out("  Rules executed: {$count}");
    }

    /** @param callable(string): void $out */
    private function runBilling(int $tenantId, callable $out): void
    {
        $out('Processing billing...');
        $billing = new BillingService();
        $pastDue = $billing->markPastDue($tenantId);
        $out("  Subscriptions marked past_due: {$pastDue}");
        $overdue = $billing->getOverdueSubscriptions($tenantId);
        $out('  Overdue subscriptions: ' . count($overdue));
    }

    /** @param callable(string): void $out */
    private function runBackup(int $tenantId, callable $out): void
    {
        $out('Creating backup...');
        $service = new BackupService();
        $result = $service->create($tenantId);
        if ($result) {
            $out('  Backup created: ' . ($result['filename'] ?? 'ok'));
            $pruned = $service->prune($tenantId);
            if ($pruned > 0) {
                $out("  Old backups pruned: {$pruned}");
            }
        } else {
            $out('  Backup failed');
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
}
