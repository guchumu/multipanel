#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * MultiPanel ERP - Cron Job Runner
 *
 * Usage: php cron/run.php [task]
 * Tasks: sync, automation, billing, backup, jobs, gdpr, cleanup, expiry, migrate, health, streams, all
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronService;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

require_once dirname(__DIR__) . '/core/helpers.php';

$task = $argv[1] ?? 'all';
$tenantId = isset($argv[2]) ? max(1, (int) $argv[2]) : 1;

echo "[MultiPanel Cron] Starting task: {$task}\n";

$result = (new CronService())->run($task, $tenantId);
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

echo "[MultiPanel Cron] Done.\n";
exit($result['ok'] ? 0 : 1);
