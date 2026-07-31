#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * MultiPanel ERP - Cron Job Runner
 *
 * Usage: php cron/run.php [task]
 * Tasks: sync, automation, billing, backup, jobs, gdpr, cleanup, expiry, all
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AutomationEngine;
use App\Services\BillingService;
use App\Services\BackupService;
use App\Services\JobProcessor;
use App\Services\GdprService;
use App\Services\Notifications\ExpiryNotificationService;
use App\Services\ServerSyncService;
use App\Repositories\ServerRepository;
use Core\Database;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

require_once dirname(__DIR__) . '/core/helpers.php';

$task = $argv[1] ?? 'all';

echo "[MultiPanel Cron] Starting task: {$task}\n";

match ($task) {
    'sync' => runServerSync(),
    'automation' => runAutomation(),
    'billing' => runBilling(),
    'backup' => runBackup(),
    'jobs' => runJobs(),
    'gdpr' => runGdpr(),
    'cleanup' => runCleanup(),
    'expiry' => runExpiryNotifications(),
    'all' => runAll(),
    default => echo "Unknown task: {$task}\n",
};

function runAll(): void
{
    runBilling();
    runServerSync();
    runAutomation();
    runBackup();
    runJobs();
    runExpiryNotifications();
    runGdpr();
    runCleanup();
}

function runExpiryNotifications(): void
{
    echo "Sending expiry notifications...\n";
    try {
        $stats = (new ExpiryNotificationService())->run(1);
        echo "  Checked: {$stats['checked']}, sent: {$stats['sent']}, skipped: {$stats['skipped']}, errors: {$stats['errors']}\n";
    } catch (\Throwable $e) {
        echo "  Expiry notifications failed: {$e->getMessage()}\n";
    }
}

function runServerSync(): void
{
    echo "Syncing servers...\n";
    $sync = new ServerSyncService();
    $repo = new ServerRepository();

    foreach ($repo->allByTenant(1) as $server) {
        $result = $sync->sync($server);
        echo "  Server {$server->name}: " . ($result ? 'OK' : 'FAIL') . "\n";
    }
}

function runAutomation(): void
{
    echo "Running automation engine...\n";
    $engine = new AutomationEngine();
    $count = $engine->runAll(1);
    echo "  Rules executed: {$count}\n";
}

function runBilling(): void
{
    echo "Processing billing...\n";
    $billing = new BillingService();
    $pastDue = $billing->markPastDue(1);
    echo "  Subscriptions marked past_due: {$pastDue}\n";

    $overdue = $billing->getOverdueSubscriptions(1);
    echo "  Overdue subscriptions: " . count($overdue) . "\n";
}

function runBackup(): void
{
    echo "Creating backup...\n";
    $service = new BackupService();
    $result = $service->create(1);
    if ($result) {
        echo "  Backup created: {$result['filename']}\n";
        $pruned = $service->prune(1);
        if ($pruned > 0) {
            echo "  Old backups pruned: {$pruned}\n";
        }
    } else {
        echo "  Backup failed\n";
    }
}

function runJobs(): void
{
    echo "Processing job queue...\n";
    $count = (new JobProcessor())->process();
    echo "  Jobs processed: {$count}\n";
}

function runGdpr(): void
{
    echo "Processing GDPR requests...\n";
    $count = (new GdprService())->processPending();
    echo "  GDPR requests processed: {$count}\n";
}

function runCleanup(): void
{
    echo "Cleaning up...\n";

    Database::getInstance()->query('DELETE FROM user_sessions WHERE expires_at < NOW()');
    Database::getInstance()->query('DELETE FROM server_stats WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
    Database::getInstance()->query("DELETE FROM notifications WHERE status = 'sent' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

    echo "  Cleanup complete.\n";
}

echo "[MultiPanel Cron] Done.\n";
