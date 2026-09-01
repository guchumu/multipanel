<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Elimina filas duplicadas en playback_sessions creadas por polls de sync/streams.
 */
final class PlaybackSessionDedupeService
{
    /** Misma reproducción con pausa corta (poll cada ~3 min). */
    private const GAP_SECONDS = 2700;

    /**
     * @return array{
     *     tenants: int,
     *     scanned: int,
     *     clusters: int,
     *     merged: int,
     *     deleted: int,
     *     apply: bool,
     *     since: ?string
     * }
     */
    public function run(int $tenantId = 0, ?string $since = null, bool $apply = false): array
    {
        $since = $this->normalizeSince($since);
        $tenantIds = $this->tenantIds($tenantId);
        $scanned = 0;
        $clusters = 0;
        $merged = 0;
        $deleted = 0;

        foreach ($tenantIds as $tid) {
            $tid = (int) $tid;
            $dupResult = $this->mergeDuplicateExternalIds($tid, $since, $apply);
            $clusters += $dupResult['clusters'];
            $merged += $dupResult['merged'];
            $deleted += $dupResult['deleted'];

            $rows = $this->fetchRows($tid, $since);
            $scanned += count($rows);
            $groups = $this->clusterRows($rows);

            foreach ($groups as $group) {
                if (count($group) < 2) {
                    continue;
                }
                $clusters++;
                $result = $this->mergeCluster($group, $apply);
                if ($result['merged']) {
                    $merged++;
                    $deleted += $result['deleted'];
                }
            }
        }

        return [
            'tenants' => count($tenantIds),
            'scanned' => $scanned,
            'clusters' => $clusters,
            'merged' => $merged,
            'deleted' => $deleted,
            'apply' => $apply,
            'since' => $since,
        ];
    }

    /**
     * @return array{clusters: int, merged: int, deleted: int}
     */
    private function mergeDuplicateExternalIds(int $tenantId, ?string $since, bool $apply): array
    {
        $params = [$tenantId];
        $sinceSql = '';
        if ($since !== null) {
            $sinceSql = ' AND started_at >= ?';
            $params[] = $since;
        }

        $groups = Database::getInstance()->fetchAll(
            "SELECT server_id, external_session_id
             FROM playback_sessions
             WHERE tenant_id = ? AND external_session_id IS NOT NULL AND TRIM(external_session_id) != ''{$sinceSql}
             GROUP BY server_id, external_session_id
             HAVING COUNT(*) > 1",
            $params
        ) ?: [];

        $clusters = 0;
        $merged = 0;
        $deleted = 0;

        foreach ($groups as $group) {
            $rows = Database::getInstance()->fetchAll(
                'SELECT ps.id, ps.tenant_id, ps.server_id, ps.media_user_id, ps.external_session_id, ps.title, ps.player,
                        ps.started_at, ps.ended_at, ps.duration_seconds, ps.country, ps.ip_address,
                        mu.username, mu.display_name
                 FROM playback_sessions ps
                 LEFT JOIN media_users mu ON mu.id = ps.media_user_id
                 WHERE ps.server_id = ? AND ps.external_session_id = ?
                 ORDER BY ps.started_at, ps.id',
                [(int) $group['server_id'], (string) $group['external_session_id']]
            ) ?: [];

            if (count($rows) < 2) {
                continue;
            }

            $clusters++;
            $result = $this->mergeCluster($rows, $apply);
            if ($result['merged']) {
                $merged++;
                $deleted += $result['deleted'];
            }
        }

        return ['clusters' => $clusters, 'merged' => $merged, 'deleted' => $deleted];
    }

    /** @return list<int> */
    private function tenantIds(int $tenantId): array
    {
        if ($tenantId > 0) {
            return [$tenantId];
        }

        $rows = Database::getInstance()->fetchAll(
            'SELECT DISTINCT tenant_id FROM playback_sessions ORDER BY tenant_id'
        );

        return array_map(static fn (array $r): int => (int) $r['tenant_id'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(int $tenantId, ?string $since): array
    {
        $params = [$tenantId];
        $sinceSql = '';
        if ($since !== null) {
            $sinceSql = ' AND ps.started_at >= ?';
            $params[] = $since;
        }

        return Database::getInstance()->fetchAll(
            "SELECT ps.id, ps.tenant_id, ps.server_id, ps.media_user_id, ps.external_session_id, ps.title, ps.player,
                    ps.started_at, ps.ended_at, ps.duration_seconds, ps.country, ps.ip_address,
                    mu.username, mu.display_name
             FROM playback_sessions ps
             LEFT JOIN media_users mu ON mu.id = ps.media_user_id
             WHERE ps.tenant_id = ?{$sinceSql}
             ORDER BY ps.server_id, ps.title, ps.started_at, ps.id",
            $params
        ) ?: [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private function clusterRows(array $rows): array
    {
        $groups = [];
        $currentKey = null;
        /** @var list<array<string, mixed>> $current */
        $current = [];

        foreach ($rows as $row) {
            $key = $this->groupKey($row);
            if ($currentKey === null || $key !== $currentKey || !$this->continuesCluster($current, $row)) {
                if ($current !== []) {
                    $groups[] = $current;
                }
                $current = [$row];
                $currentKey = $key;
                continue;
            }
            $current[] = $row;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $cluster
     * @return array{merged: bool, deleted: int}
     */
    private function mergeCluster(array $cluster, bool $apply): array
    {
        if (count($cluster) < 2) {
            return ['merged' => false, 'deleted' => 0];
        }

        $keep = $cluster[0];
        foreach ($cluster as $row) {
            if (strtotime((string) $row['started_at']) < strtotime((string) $keep['started_at'])) {
                $keep = $row;
            }
        }

        $keepId = (int) $keep['id'];
        $deleteIds = [];
        foreach ($cluster as $row) {
            $id = (int) $row['id'];
            if ($id !== $keepId) {
                $deleteIds[] = $id;
            }
        }

        if ($deleteIds === []) {
            return ['merged' => false, 'deleted' => 0];
        }

        $minStarted = (string) $keep['started_at'];
        $maxEndedTs = 0;
        $bestExternalId = (string) ($keep['external_session_id'] ?? '');
        $country = $keep['country'] ?? null;
        $ip = $keep['ip_address'] ?? null;
        $bestMediaUserId = (int) ($keep['media_user_id'] ?? 0) > 0 ? (int) $keep['media_user_id'] : null;

        foreach ($cluster as $row) {
            $startedTs = strtotime((string) $row['started_at']);
            if ($startedTs < strtotime($minStarted)) {
                $minStarted = (string) $row['started_at'];
            }

            $endedRaw = $row['ended_at'] ?? null;
            $endTs = $endedRaw ? strtotime((string) $endedRaw) : $startedTs;
            if ($endTs > $maxEndedTs) {
                $maxEndedTs = $endTs;
            }

            $ext = (string) ($row['external_session_id'] ?? '');
            if ($this->externalIdScore($ext) > $this->externalIdScore($bestExternalId)) {
                $bestExternalId = $ext;
            }

            if ($country === null && !empty($row['country'])) {
                $country = $row['country'];
            }
            if ($ip === null && !empty($row['ip_address'])) {
                $ip = $row['ip_address'];
            }

            $rowUserId = (int) ($row['media_user_id'] ?? 0);
            if ($rowUserId > 0) {
                $bestMediaUserId = $rowUserId;
            }
        }

        $endedAt = date('Y-m-d H:i:s', $maxEndedTs);
        $duration = max(0, $maxEndedTs - strtotime($minStarted));

        if (!$apply) {
            return ['merged' => true, 'deleted' => count($deleteIds)];
        }

        $db = Database::getInstance();
        $update = [
            'started_at' => $minStarted,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
        ];
        if ($bestExternalId !== '') {
            $update['external_session_id'] = $bestExternalId;
        }
        if ($country !== null) {
            $update['country'] = $country;
        }
        if ($ip !== null) {
            $update['ip_address'] = $ip;
        }
        if ($bestMediaUserId !== null && $bestMediaUserId > 0) {
            $update['media_user_id'] = $bestMediaUserId;
        }

        $db->update('playback_sessions', $update, 'id = ?', [$keepId]);

        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $db->query("DELETE FROM playback_sessions WHERE id IN ({$placeholders})", $deleteIds);

        return ['merged' => true, 'deleted' => count($deleteIds)];
    }

    /** @param list<array<string, mixed>> $cluster */
    private function continuesCluster(array $cluster, array $row): bool
    {
        $clusterEnd = 0;
        foreach ($cluster as $item) {
            $startedTs = strtotime((string) $item['started_at']);
            $endedRaw = $item['ended_at'] ?? null;
            $endTs = $endedRaw ? strtotime((string) $endedRaw) : $startedTs;
            $clusterEnd = max($clusterEnd, $endTs, $startedTs);
        }

        $newStart = strtotime((string) $row['started_at']);

        return $newStart <= ($clusterEnd + self::GAP_SECONDS);
    }

    /** @param array<string, mixed> $row */
    private function groupKey(array $row): string
    {
        $title = mb_strtolower(trim((string) ($row['title'] ?? '')));
        if ($title === '') {
            $title = (string) ($row['external_session_id'] ?? 'unknown');
        }

        return implode('|', [
            (int) ($row['server_id'] ?? 0),
            $title,
        ]);
    }

    private function externalIdScore(string $externalId): int
    {
        if (str_starts_with($externalId, 'sid:')) {
            return 3;
        }
        if (str_starts_with($externalId, 'hash:')) {
            return 2;
        }

        return $externalId !== '' ? 1 : 0;
    }

    private function normalizeSince(?string $since): ?string
    {
        $since = trim((string) $since);
        if ($since === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            throw new \InvalidArgumentException('Formato --since inválido; usa YYYY-MM-DD.');
        }

        return $since . ' 00:00:00';
    }
}
