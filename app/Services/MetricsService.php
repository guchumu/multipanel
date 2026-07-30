<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Prometheus-compatible metrics exporter.
 */
final class MetricsService
{
    public function render(): string
    {
        $lines = [];
        $db = Database::getInstance();

        $this->gauge($lines, 'multipanel_servers_total', 'Total servers', (int) ($db->fetchOne('SELECT COUNT(*) as c FROM servers')['c'] ?? 0));
        $this->gauge($lines, 'multipanel_servers_online', 'Online servers', (int) ($db->fetchOne("SELECT COUNT(*) as c FROM servers WHERE status = 'online'")['c'] ?? 0));
        $this->gauge($lines, 'multipanel_media_users_total', 'Total media users', (int) ($db->fetchOne('SELECT COUNT(*) as c FROM media_users WHERE deleted_at IS NULL')['c'] ?? 0));
        $this->gauge($lines, 'multipanel_media_users_active', 'Active media users', (int) ($db->fetchOne("SELECT COUNT(*) as c FROM media_users WHERE status = 'active' AND deleted_at IS NULL")['c'] ?? 0));
        $this->gauge($lines, 'multipanel_subscriptions_active', 'Active subscriptions', (int) ($db->fetchOne("SELECT COUNT(*) as c FROM subscriptions WHERE status = 'active'")['c'] ?? 0));
        $this->gauge($lines, 'multipanel_tickets_open', 'Open tickets', (int) ($db->fetchOne("SELECT COUNT(*) as c FROM tickets WHERE status IN ('open','pending')")['c'] ?? 0));
        $this->gauge($lines, 'multipanel_jobs_pending', 'Pending jobs', (int) ($db->fetchOne("SELECT COUNT(*) as c FROM jobs WHERE status = 'pending'")['c'] ?? 0));
        $this->gauge($lines, 'multipanel_backups_total', 'Total backups', (int) ($db->fetchOne("SELECT COUNT(*) as c FROM backups WHERE status = 'completed'")['c'] ?? 0));

        $revenue = (float) ($db->fetchOne("SELECT COALESCE(SUM(total),0) as t FROM invoices WHERE status = 'paid'")['t'] ?? 0);
        $this->gauge($lines, 'multipanel_revenue_total_eur', 'Total revenue EUR', $revenue);

        $lines[] = '# HELP multipanel_info MultiPanel version info';
        $lines[] = '# TYPE multipanel_info gauge';
        $lines[] = 'multipanel_info{version="' . config('app.version', '1.0.0') . '"} 1';

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines */
    private function gauge(array &$lines, string $name, string $help, int|float $value): void
    {
        $lines[] = '# HELP ' . $name . ' ' . $help;
        $lines[] = '# TYPE ' . $name . ' gauge';
        $lines[] = $name . ' ' . $value;
    }
}
