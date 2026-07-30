#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * MultiPanel health check script for monitoring/load balancers.
 *
 * Usage: php scripts/health-check.php
 * Exit 0 = healthy, 1 = unhealthy
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/core/helpers.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$checks = [];
$healthy = true;

$checks['php'] = version_compare(PHP_VERSION, '8.3.0', '>=');
$checks['storage'] = is_writable(storage_path());
$checks['env'] = file_exists(base_path('.env'));

try {
    \Core\Database::getInstance()->fetchOne('SELECT 1 as ok');
    $checks['database'] = true;
} catch (Throwable) {
    $checks['database'] = false;
}

if (env('REDIS_ENABLED', false)) {
    try {
        \Core\RedisClient::getInstance()->get('health_check');
        $checks['redis'] = true;
    } catch (Throwable) {
        $checks['redis'] = false;
    }
}

foreach ($checks as $name => $ok) {
    $status = $ok ? 'OK' : 'FAIL';
    echo str_pad($name, 12) . $status . "\n";
    if (!$ok) {
        $healthy = false;
    }
}

exit($healthy ? 0 : 1);
