<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use DateInterval;
use DateTimeImmutable;

/**
 * Advanced statistics aggregation service.
 */
final class StatsService
{
    public const PRESETS = ['7d', '30d', 'week', 'month', 'custom'];

    public function getDashboardStats(int $tenantId): array
    {
        return [
            'users' => $this->userStats($tenantId),
            'streaming' => $this->streamingStats($tenantId),
            'servers' => $this->serverStats($tenantId),
            'billing' => $this->billingStats($tenantId),
        ];
    }

    /**
     * @return array{preset: string, from: string, to: string, from_date: string, to_date: string, label: string}
     */
    public function resolvePeriod(string $preset = '30d', ?string $from = null, ?string $to = null): array
    {
        $preset = in_array($preset, self::PRESETS, true) ? $preset : '30d';
        $tz = new \DateTimeZone(config('app.timezone', 'UTC'));
        $today = new DateTimeImmutable('today', $tz);
        $now = new DateTimeImmutable('now', $tz);

        $start = $today->sub(new DateInterval('P29D'));
        $end = $now;
        $label = 'Últimos 30 días';

        if ($preset === '7d') {
            $start = $today->sub(new DateInterval('P6D'));
            $label = 'Últimos 7 días';
        } elseif ($preset === 'week') {
            $dow = (int) $today->format('N');
            $start = $dow === 1 ? $today : $today->sub(new DateInterval('P' . ($dow - 1) . 'D'));
            $label = 'Esta semana';
        } elseif ($preset === 'month') {
            $start = $today->modify('first day of this month');
            $label = 'Este mes';
        } elseif ($preset === 'custom') {
            $startDate = $this->parseDate($from, $tz) ?? $today->sub(new DateInterval('P29D'));
            $endDate = $this->parseDate($to, $tz) ?? $today;
            if ($endDate < $startDate) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }
            $start = $startDate;
            $end = $endDate->setTime(23, 59, 59);
            $label = $startDate->format('d/m/Y') . ' – ' . $endDate->format('d/m/Y');
        }

        if ($preset !== 'custom') {
            $end = $now;
        }

        return [
            'preset' => $preset,
            'from' => $start->format('Y-m-d 00:00:00'),
            'to' => $end->format('Y-m-d H:i:s'),
            'from_date' => $start->format('Y-m-d'),
            'to_date' => $end->format('Y-m-d'),
            'label' => $label,
        ];
    }

    public function normalizeMediaType(?string $type): string
    {
        $type = strtolower(trim((string) $type));
        return in_array($type, ['movie', 'series'], true) ? $type : '';
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

        $liveSessions = (int) ($db->fetchOne(
            "SELECT COALESCE(SUM(active_sessions),0) as c FROM servers WHERE tenant_id = ? AND deleted_at IS NULL",
            [$tenantId]
        )['c'] ?? 0);

        return [
            'today_sessions' => max((int) ($today['sessions'] ?? 0), $liveSessions > 0 ? 1 : 0),
            'today_hours' => round((int) ($today['seconds'] ?? 0) / 3600, 1),
            'month_sessions' => (int) ($month['sessions'] ?? 0),
            'month_hours' => round((int) ($month['seconds'] ?? 0) / 3600, 1),
            'live_sessions' => $liveSessions,
        ];
    }

    private function serverStats(int $tenantId): array
    {
        $db = Database::getInstance();
        return [
            'online' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM servers WHERE tenant_id = ? AND status = 'online' AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
            'offline' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM servers WHERE tenant_id = ? AND status IN ('offline','error','maintenance') AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
            'active_sessions' => (int) ($db->fetchOne("SELECT COALESCE(SUM(active_sessions),0) as c FROM servers WHERE tenant_id = ? AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
            'total' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM servers WHERE tenant_id = ? AND deleted_at IS NULL", [$tenantId])['c'] ?? 0),
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

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, array{date: string, sessions: int, hours: float}>
     */
    public function getDailyStreaming(int $tenantId, array $period, string $mediaType = ''): array
    {
        $db = Database::getInstance();
        [$typeSql, $typeParams] = $this->mediaTypeClause($mediaType);
        $params = array_merge([$tenantId, $period['from'], $period['to']], $typeParams);

        $rows = $db->fetchAll(
            "SELECT DATE(started_at) as date, COUNT(*) as sessions, COALESCE(SUM(duration_seconds),0) as seconds
             FROM playback_sessions
             WHERE tenant_id = ? AND started_at >= ? AND started_at <= ?{$typeSql}
             GROUP BY DATE(started_at) ORDER BY date",
            $params
        );

        if ($rows === [] && $mediaType === '') {
            $fallback = $db->fetchAll(
                "SELECT DATE(ss.recorded_at) as date, MAX(ss.active_sessions) as sessions
                 FROM server_stats ss
                 INNER JOIN servers s ON s.id = ss.server_id AND s.tenant_id = ? AND s.deleted_at IS NULL
                 WHERE ss.recorded_at >= ? AND ss.recorded_at <= ?
                 GROUP BY DATE(ss.recorded_at)
                 ORDER BY date",
                [$tenantId, $period['from'], $period['to']]
            );

            return array_map(fn ($r) => [
                'date' => $r['date'],
                'sessions' => (int) $r['sessions'],
                'hours' => 0.0,
            ], $fallback);
        }

        return array_map(fn ($r) => [
            'date' => $r['date'],
            'sessions' => (int) $r['sessions'],
            'hours' => round((int) $r['seconds'] / 3600, 1),
        ], $rows);
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, array{country: string, code: string, count: int}>
     */
    public function getTopCountries(int $tenantId, array $period, string $mediaType = '', int $limit = 10): array
    {
        [$typeSql, $typeParams] = $this->mediaTypeClause($mediaType, 'ps');
        $params = array_merge([$tenantId, $period['from'], $period['to']], $typeParams, [$limit]);

        $rows = Database::getInstance()->fetchAll(
            "SELECT ps.country as code, COUNT(*) as count FROM playback_sessions ps
             WHERE ps.tenant_id = ? AND ps.started_at >= ? AND ps.started_at <= ?
               AND ps.country IS NOT NULL AND ps.country != ''{$typeSql}
             GROUP BY ps.country ORDER BY count DESC LIMIT ?",
            $params
        );

        return array_map(static fn ($r) => [
            'code' => (string) $r['code'],
            'country' => GeoIpService::countryName((string) $r['code']),
            'count' => (int) $r['count'],
        ], $rows);
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, array{device: string, count: int}>
     */
    public function getTopDevices(int $tenantId, array $period, string $mediaType = '', int $limit = 10): array
    {
        [$typeSql, $typeParams] = $this->mediaTypeClause($mediaType);
        $params = array_merge([$tenantId, $period['from'], $period['to']], $typeParams, [$limit]);

        return Database::getInstance()->fetchAll(
            "SELECT COALESCE(device, player, 'Desconocido') as device, COUNT(*) as count
             FROM playback_sessions WHERE tenant_id = ? AND started_at >= ? AND started_at <= ?{$typeSql}
             GROUP BY device ORDER BY count DESC LIMIT ?",
            $params
        );
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, array{title: string, count: int}>
     */
    public function getTopContent(int $tenantId, array $period, string $mediaType = '', int $limit = 10): array
    {
        [$typeSql, $typeParams] = $this->mediaTypeClause($mediaType);
        $params = array_merge([$tenantId, $period['from'], $period['to']], $typeParams, [$limit]);

        return Database::getInstance()->fetchAll(
            "SELECT title, COUNT(*) as count FROM playback_sessions
             WHERE tenant_id = ? AND started_at >= ? AND started_at <= ?
               AND title IS NOT NULL AND title != ''{$typeSql}
             GROUP BY title ORDER BY count DESC LIMIT ?",
            $params
        );
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, array{name: string, uuid: string, count: int, hours: float}>
     */
    public function getTopUsers(int $tenantId, array $period, string $mediaType = '', int $limit = 10): array
    {
        [$typeSql, $typeParams] = $this->mediaTypeClause($mediaType, 'ps');
        $params = array_merge([$tenantId, $period['from'], $period['to']], $typeParams, [$limit]);

        $rows = Database::getInstance()->fetchAll(
            "SELECT ps.media_user_id, mu.uuid, mu.display_name, mu.username,
                    COUNT(*) as count, COALESCE(SUM(ps.duration_seconds),0) as seconds
             FROM playback_sessions ps
             LEFT JOIN media_users mu ON mu.id = ps.media_user_id
             WHERE ps.tenant_id = ? AND ps.started_at >= ? AND ps.started_at <= ?
               AND ps.media_user_id IS NOT NULL{$typeSql}
             GROUP BY ps.media_user_id, mu.uuid, mu.display_name, mu.username
             ORDER BY count DESC LIMIT ?",
            $params
        );

        return array_map(static fn ($r) => [
            'name' => (string) ($r['display_name'] ?: $r['username'] ?: 'Usuario'),
            'uuid' => (string) ($r['uuid'] ?? ''),
            'count' => (int) $r['count'],
            'hours' => round((int) $r['seconds'] / 3600, 1),
        ], $rows);
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, array{hour: int, sessions: int}>
     */
    public function getHourlyDistribution(int $tenantId, array $period, string $mediaType = ''): array
    {
        [$typeSql, $typeParams] = $this->mediaTypeClause($mediaType);
        $params = array_merge([$tenantId, $period['from'], $period['to']], $typeParams);

        return Database::getInstance()->fetchAll(
            "SELECT HOUR(started_at) as hour, COUNT(*) as sessions
             FROM playback_sessions WHERE tenant_id = ? AND started_at >= ? AND started_at <= ?{$typeSql}
             GROUP BY HOUR(started_at) ORDER BY hour",
            $params
        );
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array{movie: int, series: int, other: int}
     */
    public function getTypeBreakdown(int $tenantId, array $period): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT LOWER(COALESCE(media_type,'')) as media_type, COUNT(*) as count
             FROM playback_sessions
             WHERE tenant_id = ? AND started_at >= ? AND started_at <= ?
             GROUP BY LOWER(COALESCE(media_type,''))",
            [$tenantId, $period['from'], $period['to']]
        );

        $out = ['movie' => 0, 'series' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $type = (string) $row['media_type'];
            $count = (int) $row['count'];
            if (in_array($type, ['movie', 'film'], true)) {
                $out['movie'] += $count;
            } elseif (in_array($type, ['episode', 'show', 'series'], true)) {
                $out['series'] += $count;
            } else {
                $out['other'] += $count;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function mediaTypeClause(string $type, string $alias = ''): array
    {
        $col = $alias !== '' ? "{$alias}.media_type" : 'media_type';
        if ($type === 'movie') {
            return [" AND LOWER(COALESCE({$col},'')) IN ('movie','film')", []];
        }
        if ($type === 'series') {
            return [" AND LOWER(COALESCE({$col},'')) IN ('episode','show','series')", []];
        }

        return ['', []];
    }

    private function parseDate(?string $value, \DateTimeZone $tz): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, $tz);
        } catch (\Exception) {
            return null;
        }
    }
}
