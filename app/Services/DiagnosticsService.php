<?php

declare(strict_types=1);

namespace App\Services;

/**
 * System diagnostics and health checks.
 */
final class DiagnosticsService
{
    /** @return array<int, array{name: string, status: string, message: string, value?: mixed}> */
    public function runAll(): array
    {
        return [
            $this->checkPhp(),
            $this->checkExtensions(),
            $this->checkDatabase(),
            $this->checkStorage(),
            $this->checkRedis(),
            $this->checkCron(),
            $this->checkMail(),
            $this->checkTelegram(),
            $this->checkSsl(),
            $this->checkDiskSpace(),
            $this->checkMemory(),
            $this->checkMigrations(),
            $this->checkLicense(),
        ];
    }

    public function getScore(): int
    {
        $checks = $this->runAll();
        $passed = count(array_filter($checks, fn ($c) => $c['status'] === 'ok'));
        return (int) round(($passed / max(count($checks), 1)) * 100);
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkPhp(): array
    {
        $ok = version_compare(PHP_VERSION, '8.3.0', '>=');
        return [
            'name' => 'PHP Version',
            'status' => $ok ? 'ok' : 'error',
            'message' => 'PHP ' . PHP_VERSION . ($ok ? '' : ' — se requiere 8.3+'),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkExtensions(): array
    {
        $required = ['pdo', 'pdo_mysql', 'json', 'openssl', 'mbstring', 'curl'];
        $missing = array_filter($required, fn ($e) => !extension_loaded($e));

        return [
            'name' => 'Extensiones PHP',
            'status' => empty($missing) ? 'ok' : 'error',
            'message' => empty($missing) ? 'Todas instaladas' : 'Faltan: ' . implode(', ', $missing),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkDatabase(): array
    {
        try {
            $db = \Core\Database::getInstance();
            $db->fetchOne('SELECT 1 as ok');
            $tables = $db->fetchOne("SELECT COUNT(*) as c FROM information_schema.tables WHERE table_schema = ?", [
                config('database.database'),
            ]);
            return [
                'name' => 'Base de datos',
                'status' => 'ok',
                'message' => 'Conectada — ' . ($tables['c'] ?? 0) . ' tablas',
            ];
        } catch (\Throwable $e) {
            return ['name' => 'Base de datos', 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkStorage(): array
    {
        $dirs = ['storage/logs', 'storage/cache', 'storage/backups', 'storage/sessions', 'storage/exports'];
        $issues = [];

        foreach ($dirs as $dir) {
            $path = base_path($dir);
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                $issues[] = $dir;
            }
        }

        return [
            'name' => 'Permisos storage',
            'status' => empty($issues) ? 'ok' : 'warning',
            'message' => empty($issues) ? 'Escritura OK' : 'Sin permiso: ' . implode(', ', $issues),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkRedis(): array
    {
        if (!config('redis.enabled', false)) {
            return ['name' => 'Redis', 'status' => 'info', 'message' => 'Desactivado (usa cache en fichero)'];
        }

        $ok = \Core\RedisClient::isAvailable();
        return [
            'name' => 'Redis',
            'status' => $ok ? 'ok' : 'warning',
            'message' => $ok ? 'Conectado' : 'No disponible — fallback a fichero',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkCron(): array
    {
        $cronFile = base_path('cron/run.php');
        $ok = file_exists($cronFile);
        return [
            'name' => 'Cron Jobs',
            'status' => $ok ? 'ok' : 'warning',
            'message' => $ok ? 'Script cron/run.php presente' : 'Script cron no encontrado',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkMail(): array
    {
        $host = config('mail.host');
        $configured = !empty($host) && $host !== 'smtp.example.com';
        return [
            'name' => 'SMTP',
            'status' => $configured ? 'ok' : 'info',
            'message' => $configured ? "Configurado: {$host}" : 'No configurado',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkTelegram(): array
    {
        $token = config('telegram.bot_token', env('TELEGRAM_BOT_TOKEN', ''));
        return [
            'name' => 'Telegram',
            'status' => $token ? 'ok' : 'info',
            'message' => $token ? 'Bot configurado' : 'No configurado',
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkSsl(): array
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return [
            'name' => 'HTTPS',
            'status' => $isHttps ? 'ok' : 'warning',
            'message' => $isHttps ? 'Conexión segura' : 'HTTP — recomendado HTTPS en producción',
        ];
    }

    /** @return array{name: string, status: string, message: string, value?: mixed} */
    private function checkDiskSpace(): array
    {
        $path = base_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false) {
            return ['name' => 'Espacio disco', 'status' => 'info', 'message' => 'No disponible'];
        }

        $pct = round(($free / $total) * 100, 1);
        return [
            'name' => 'Espacio disco',
            'status' => $pct > 10 ? 'ok' : 'error',
            'message' => "{$pct}% libre (" . $this->formatBytes($free) . ')',
            'value' => $pct,
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkMemory(): array
    {
        $limit = ini_get('memory_limit');
        return [
            'name' => 'Memoria PHP',
            'status' => 'ok',
            'message' => "Límite: {$limit}",
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkMigrations(): array
    {
        try {
            $updater = new \Core\Updater();
            $pending = count($updater->getPendingMigrations());
            return [
                'name' => 'Migraciones',
                'status' => $pending === 0 ? 'ok' : 'warning',
                'message' => $pending === 0 ? 'Al día' : "{$pending} pendiente(s)",
            ];
        } catch (\Throwable) {
            return ['name' => 'Migraciones', 'status' => 'info', 'message' => 'No verificable'];
        }
    }

    /** @return array{name: string, status: string, message: string} */
    private function checkLicense(): array
    {
        $license = new LicenseService();
        $valid = $license->isValid();
        return [
            'name' => 'Licencia',
            'status' => $valid ? 'ok' : 'warning',
            'message' => $valid ? 'Licencia válida' : ($license->getStatusMessage() ?? 'Sin licencia'),
        ];
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
