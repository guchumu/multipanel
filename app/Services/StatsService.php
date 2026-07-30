<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Advanced statistics aggregation service.
 */
final class StatsService
{
    public function getDashboardStats(int $tenantId): array
    {
        $db = Database::getInstance();

        return [
            'users' => $this->userStats($tenantId),
            'streaming' => $this->streamingStats($tenantId),
            'servers' => $this->serverStats($tenantId),
            'billing' => $this->billingStats($tenantId),
        ];
    }

    private function userStats(int $tenantId): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT status, COUNT(*) as count FROM media_users WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY status",
            [$tenantId]
        );

        $stats = ['active' => 0, 'suspended' => 0, 'pending' => 0, 'expired' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['count'];
            $stats['total'] += (int) $row['count'];
        }
        return $stats;
    }

    private function streamingStats(int $tenantId): array
    {
        $db = Database::getInstance();

        $today = $db->fetchOne(
            "SELECT COUNT(*) as sessions, COALESCE(SUM(duration_seconds),0) as seconds
             FROM playback_sessions WHERE tenant_id = ? AND DATE(started_at) = CURDATE()",
            [$tenantId]
        );

        $month = $db->fetchOne(
            "SELECT COUNT(*) as sessions, COALESCE(SUM(duration_seconds),0) as seconds
             FROM playback_sessions WHERE tenant_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$tenantId]
        );

        return [
            'today_sessions' => (int) ($today['sessions'] ?? 0),
            'today_hours' => round((int) ($today['seconds'] ?? 0) / 3600, 1),
            'month_sessions' => (int) ($month['sessions'] ?? 0),
            'month_hours' => round((int) ($month['seconds'] ?? 0) / 3600, 1),
        ];
    }

    private function serverStats(int $tenantId): array
    {
        $db = Database::getInstance();
        return [
            'online' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM servers WHERE tenant_id = ? AND status = 'online' AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
            'offline' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM servers WHERE tenant_id = ? AND status = 'offline' AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
            'active_sessions' => (int) ($db->fetchOne("SELECT COALESCE(SUM(active_sessions),0) as c FROM servers WHERE tenant_id = ? AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
        ];
    }

    private function billingStats(int $tenantId): array
    {
        $db = Database::getInstance();
        return [
            'mrr' => (float) ($db->fetchOne(
                "SELECT COALESCE(SUM(s.amount),0) as m FROM subscriptions s WHERE s.tenant_id = ? AND s.status = 'active'",
                [$tenantId]
            )['m'] ?? 0),
            'past_due' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM subscriptions WHERE tenant_id = ? AND status = 'past_due'", [$tenantId])['c'] ?? 0),
        ];
    }

    /** @return array<int, array{date: string, sessions: int, hours: float}> */
    public function getDailyStreaming(int $tenantId, int $days = 30): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT DATE(started_at) as date, COUNT(*) as sessions, COALESCE(SUM(duration_seconds),0) as seconds
             FROM playback_sessions
             WHERE tenant_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(started_at) ORDER BY date",
            [$tenantId, $days]
        );

        return array_map(fn ($r) => [
            'date' => $r['date'],
            'sessions' => (int) $r['sessions'],
            'hours' => round((int) $r['seconds'] / 3600, 1),
        ], $rows);
    }

    /** @return array<int, array{country: string, count: int}> */
    public function getTopCountries(int $tenantId, int $limit = 10): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT country, COUNT(*) as count FROM playback_sessions
             WHERE tenant_id = ? AND country IS NOT NULL
             GROUP BY country ORDER BY count DESC LIMIT ?",
            [$tenantId, $limit]
        );
    }

    /** @return array<int, array{device: string, count: int}> */
    public function getTopDevices(int $tenantId, int $limit = 10): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT COALESCE(device, player, 'Desconocido') as device, COUNT(*) as count
             FROM playback_sessions WHERE tenant_id = ?
             GROUP BY device ORDER BY count DESC LIMIT ?",
            [$tenantId, $limit]
        );
    }

    /** @return array<int, array{title: string, count: int}> */
    public function getTopContent(int $tenantId, int $limit = 10): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT title, COUNT(*) as count FROM playback_sessions
             WHERE tenant_id = ? AND title IS NOT NULL
             GROUP BY title ORDER BY count DESC LIMIT ?",
            [$tenantId, $limit]
        );
    }

    /** @return array<int, array{hour: int, sessions: int}> */
    public function getHourlyDistribution(int $tenantId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT HOUR(started_at) as hour, COUNT(*) as sessions
             FROM playback_sessions WHERE tenant_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY HOUR(started_at) ORDER BY hour",
            [$tenantId]
        );
    }
}
