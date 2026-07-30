#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Verify MultiPanel installation completeness.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
require_once dirname(__DIR__) . '/core/helpers.php';

$checks = [];
$pass = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks, $pass;
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if ($ok) {
        $pass++;
    }
}

check('PHP >= 8.3', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION);
check('Composer vendor/', is_dir(dirname(__DIR__) . '/vendor'));
check('.env exists', file_exists(base_path('.env')));
check('APP_KEY set', env('APP_KEY', '') !== '');
check('JWT_SECRET set', env('JWT_SECRET', '') !== '');
check('storage writable', is_writable(storage_path()));

$requiredDirs = ['logs', 'cache', 'sessions', 'backups', 'invoices', 'realtime'];
foreach ($requiredDirs as $dir) {
    $path = storage_path($dir);
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    check("storage/{$dir}", is_dir($path) && is_writable($path));
}

try {
    \Core\Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM tenants');
    check('Database connection', true);
    check('Tenants seeded', (int) (\Core\Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM tenants')['c'] ?? 0) > 0);
    check('Permissions seeded', (int) (\Core\Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM permissions')['c'] ?? 0) > 0);
} catch (Throwable $e) {
    check('Database connection', false, $e->getMessage());
}

check('Public index.php', file_exists(public_path('index.php')));
check('Routes registered', file_exists(base_path('routes/web.php')) && file_exists(base_path('routes/api.php')));
check('PHPUnit tests', is_file(base_path('vendor/bin/phpunit')));

$total = count($checks);
echo "\nMultiPanel ERP — Verificación de instalación\n";
echo str_repeat('=', 45) . "\n";

foreach ($checks as $c) {
    $icon = $c['ok'] ? '✓' : '✗';
    $line = sprintf("  [%s] %s", $icon, $c['name']);
    if (!$c['ok'] && $c['detail']) {
        $line .= ' — ' . $c['detail'];
    }
    echo $line . "\n";
}

echo str_repeat('-', 45) . "\n";
echo "  Resultado: {$pass}/{$total} checks passed\n\n";

if ($pass === $total) {
    echo "  ✓ MultiPanel está listo para producción.\n\n";
    exit(0);
}

echo "  ⚠ Revisa los checks fallidos antes de desplegar.\n\n";
exit(1);
