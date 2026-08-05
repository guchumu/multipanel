<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use Core\Cache;
use Core\Database;
use Core\Logger;

/**
 * Enforces concurrent stream limits per media_user.
 *
 * Matching (scoped to session.server_id — never cross-server by name):
 *  1. media_users.external_id = session.user_id (Plex User.id / Jellyfin UserId)
 *  2. LOWER(username) or LOWER(display_name) = LOWER(session.user)
 *
 * Default count mode: distinct_ip — el límite es el nº de IPs de cliente distintas
 * (varias sesiones en la misma IP cuentan como 1). Alternativa: sessions.
 *
 * Policy distinct_ip when over limit: keep the N oldest IPs (first seen in the
 * session list); kill ALL sessions from the newest excess IP(s).
 * Policy sessions: keep highest-progress sessions; kill newest/excess.
 */
final class ConcurrentStreamLimitService
{
    private const KILL_DEBOUNCE_TTL = 60;

    public function __construct(
        private StreamLimitSettingsService $settings = new StreamLimitSettingsService(),
    ) {
    }

    /**
     * Annotate sessions with media_user match / limit info, and kill excess if enabled.
     *
     * @param array<int, array<string, mixed>> $sessions
     * @return array{sessions: array<int, array<string, mixed>>, killed: int, violations: int}
     */
    public function enforceAndAnnotate(int $tenantId, array $sessions): array
    {
        self::ensureViolationsTable();

        if ($sessions === []) {
            return ['sessions' => [], 'killed' => 0, 'violations' => 0];
        }

        $users = $this->loadMediaUsers($tenantId);
        $maps = $this->buildLookupMaps($users);
        $defaultLimit = $this->settings->getDefaultMaxStreams($tenantId);
        $enforce = $this->settings->isEnforcementEnabled($tenantId);
        $killMessage = $this->settings->getKillMessage($tenantId);
        $countMode = $this->settings->getCountMode($tenantId);
        $distinctIp = $countMode === StreamLimitSettingsService::COUNT_MODE_DISTINCT_IP;

        // Attach media_user_id + limit + normalized IP key.
        foreach ($sessions as $i => $session) {
            $match = $this->resolveMediaUser($session, $maps);
            $sessions[$i]['media_user_id'] = $match['id'] ?? null;
            $sessions[$i]['media_user_uuid'] = $match['uuid'] ?? null;
            $sessions[$i]['stream_limit'] = $match !== null
                ? $this->settings->resolveLimitForUser($tenantId, $match['max_streams'] ?? null)
                : $defaultLimit;
            $sessions[$i]['over_limit'] = false;
            $sessions[$i]['user_stream_count'] = 0;
            $sessions[$i]['client_ip'] = SessionClientIp::normalize((string) ($session['client_ip'] ?? ''));
            $sessions[$i]['ip_key'] = $this->ipKeyForSession($sessions[$i], $i);
        }

        // Group by media_user_id (only matched sessions).
        $byUser = [];
        foreach ($sessions as $i => $session) {
            $uid = $session['media_user_id'] ?? null;
            if ($uid === null) {
                continue;
            }
            $byUser[(int) $uid][] = $i;
        }

        $killedTotal = 0;
        $violations = 0;
        $killIndexes = [];

        foreach ($byUser as $mediaUserId => $indexes) {
            $limit = (int) ($sessions[$indexes[0]]['stream_limit'] ?? $defaultLimit);
            $serverId = (int) ($sessions[$indexes[0]]['server_id'] ?? 0);
            $username = (string) ($sessions[$indexes[0]]['user'] ?? '');

            if ($distinctIp) {
                [$count, $excessIndexes, $distinctIps] = $this->excessByDistinctIp($indexes, $sessions, $limit);
            } else {
                [$count, $excessIndexes, $distinctIps] = $this->excessBySessions($indexes, $sessions, $limit);
            }

            foreach ($indexes as $idx) {
                $sessions[$idx]['user_stream_count'] = $count;
                $sessions[$idx]['over_limit'] = $count > $limit;
                $sessions[$idx]['count_mode'] = $countMode;
            }

            if (!$enforce || $excessIndexes === []) {
                continue;
            }

            $killedIds = [];
            $titles = [];
            $allSessionIds = [];
            $allIps = [];

            foreach ($indexes as $idx) {
                $allSessionIds[] = (string) ($sessions[$idx]['session_id'] ?? '');
                $ip = (string) ($sessions[$idx]['client_ip'] ?? '');
                if ($ip !== '') {
                    $allIps[$ip] = true;
                }
                $titles[] = [
                    'title' => (string) ($sessions[$idx]['title'] ?? ''),
                    'player' => (string) ($sessions[$idx]['player'] ?? ''),
                    'server' => (string) ($sessions[$idx]['server_name'] ?? ''),
                    'session_id' => (string) ($sessions[$idx]['session_id'] ?? ''),
                    'ip' => $ip,
                ];
            }

            foreach ($excessIndexes as $idx) {
                $sessionId = trim((string) ($sessions[$idx]['session_id'] ?? ''));
                $sid = (int) ($sessions[$idx]['server_id'] ?? 0);
                if ($sessionId === '' || $sid <= 0) {
                    continue;
                }

                $debounceKey = 'stream_limit_kill_' . $sid . '_' . $sessionId;
                if (Cache::get($debounceKey)) {
                    $killIndexes[$idx] = true;
                    $killedIds[] = $sessionId;
                    continue;
                }

                $server = Server::find($sid);
                if ($server === null || (int) $server->tenant_id !== $tenantId) {
                    continue;
                }

                $ok = $this->terminateSession($server, $sessionId, $killMessage);

                if ($ok) {
                    Cache::set($debounceKey, 1, self::KILL_DEBOUNCE_TTL);
                    $killIndexes[$idx] = true;
                    $killedIds[] = $sessionId;
                    $killedTotal++;
                }
            }

            if ($killedIds !== []) {
                $violations++;
                $action = $distinctIp ? 'kill_newest_ips' : 'kill_newest';
                $clientIps = array_values(array_keys($allIps));
                if ($distinctIps !== []) {
                    $clientIps = array_values(array_unique(array_merge($clientIps, $distinctIps)));
                }

                $this->logViolation(
                    $tenantId,
                    $mediaUserId,
                    $serverId > 0 ? $serverId : null,
                    $username,
                    $count,
                    $limit,
                    $allSessionIds,
                    $killedIds,
                    $titles,
                    $killMessage,
                    $action,
                    $clientIps
                );

                AuditService::log(
                    'media_user.stream_limit_enforced',
                    'media_user',
                    $mediaUserId,
                    null,
                    [
                        'stream_count' => $count,
                        'limit' => $limit,
                        'count_mode' => $countMode,
                        'client_ips' => $clientIps,
                        'killed_session_ids' => $killedIds,
                        'action' => $action,
                        'server_id' => $serverId,
                    ],
                    null,
                    $tenantId
                );
            }
        }

        if ($killIndexes !== []) {
            $sessions = array_values(array_filter(
                $sessions,
                static fn (array $s, int|string $i): bool => !isset($killIndexes[$i]),
                ARRAY_FILTER_USE_BOTH
            ));

            // Recalcular conteo tras cortes.
            $byUserLeft = [];
            foreach ($sessions as $i => $session) {
                $uid = $session['media_user_id'] ?? null;
                if ($uid === null) {
                    continue;
                }
                $byUserLeft[(int) $uid][] = $i;
            }
            foreach ($byUserLeft as $indexes) {
                $limit = (int) ($sessions[$indexes[0]]['stream_limit'] ?? $defaultLimit);
                if ($distinctIp) {
                    [$count] = $this->excessByDistinctIp($indexes, $sessions, $limit);
                } else {
                    $count = count($indexes);
                }
                foreach ($indexes as $idx) {
                    $sessions[$idx]['user_stream_count'] = $count;
                    $sessions[$idx]['over_limit'] = $count > $limit;
                }
            }
        }

        return ['sessions' => $sessions, 'killed' => $killedTotal, 'violations' => $violations];
    }

    /**
     * Clave de agrupación: IP normalizada, o unknown:{session} si no hay IP.
     *
     * @param array<string, mixed> $session
     */
    private function ipKeyForSession(array $session, int $index): string
    {
        $ip = SessionClientIp::normalize((string) ($session['client_ip'] ?? ''));
        if ($ip !== '') {
            return $ip;
        }

        $sid = trim((string) ($session['session_id'] ?? ''));

        return 'unknown:' . ($sid !== '' ? $sid : (string) $index);
    }

    /**
     * @param array<int, int> $indexes
     * @param array<int, array<string, mixed>> $sessions
     * @return array{0: int, 1: array<int, int>, 2: array<int, string>} count, excess session indexes, distinct display IPs
     */
    private function excessByDistinctIp(array $indexes, array $sessions, int $limit): array
    {
        /** @var array<string, array{first: int, indexes: array<int, int>, display: string}> $byIp */
        $byIp = [];
        foreach ($indexes as $idx) {
            $key = (string) ($sessions[$idx]['ip_key'] ?? $this->ipKeyForSession($sessions[$idx], $idx));
            if (!isset($byIp[$key])) {
                $display = (string) ($sessions[$idx]['client_ip'] ?? '');
                $byIp[$key] = [
                    'first' => $idx,
                    'indexes' => [],
                    'display' => $display !== '' ? $display : $key,
                ];
            }
            $byIp[$key]['indexes'][] = $idx;
            $byIp[$key]['first'] = min($byIp[$key]['first'], $idx);
        }

        // IPs más antiguas primero (menor índice = apareció antes en el snapshot).
        uasort($byIp, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);

        $orderedKeys = array_keys($byIp);
        $count = count($orderedKeys);
        $distinctIps = array_values(array_map(
            static fn (string $k): string => $byIp[$k]['display'],
            $orderedKeys
        ));

        if ($count <= $limit) {
            return [$count, [], $distinctIps];
        }

        $excessKeys = array_slice($orderedKeys, $limit);
        $excessIndexes = [];
        foreach ($excessKeys as $key) {
            foreach ($byIp[$key]['indexes'] as $idx) {
                $excessIndexes[] = $idx;
            }
        }

        return [$count, $excessIndexes, $distinctIps];
    }

    /**
     * @param array<int, int> $indexes
     * @param array<int, array<string, mixed>> $sessions
     * @return array{0: int, 1: array<int, int>, 2: array<int, string>}
     */
    private function excessBySessions(array $indexes, array $sessions, int $limit): array
    {
        $count = count($indexes);
        $ips = [];
        foreach ($indexes as $idx) {
            $ip = (string) ($sessions[$idx]['client_ip'] ?? '');
            if ($ip !== '') {
                $ips[$ip] = true;
            }
        }
        $distinctIps = array_values(array_keys($ips));

        if ($count <= $limit) {
            return [$count, [], $distinctIps];
        }

        usort($indexes, static function (int $a, int $b) use ($sessions): int {
            $pa = (int) ($sessions[$a]['progress'] ?? 0);
            $pb = (int) ($sessions[$b]['progress'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }

            return $a <=> $b;
        });

        return [$count, array_slice($indexes, $limit), $distinctIps];
    }

    /**
     * Cron entry: fetch live sessions and enforce (bypasses snapshot cache).
     *
     * @return array{checked: int, killed: int, violations: int}
     */
    public function runForTenant(int $tenantId): array
    {
        if (!$this->settings->isEnforcementEnabled($tenantId)) {
            return ['checked' => 0, 'killed' => 0, 'violations' => 0];
        }

        Cache::forget('activity_snapshot_' . $tenantId);
        $snapshot = (new StreamingActivityService())->getSnapshot($tenantId);

        return [
            'checked' => (int) ($snapshot['total_count'] ?? count($snapshot['sessions'] ?? [])),
            'killed' => (int) ($snapshot['stream_limit_killed'] ?? 0),
            'violations' => (int) ($snapshot['stream_limit_violations'] ?? 0),
        ];
    }

    private function terminateSession(Server $server, string $sessionId, string $reason): bool
    {
        try {
            $media = MediaServerFactory::make($server);
            if (!($media instanceof PlexService || $media instanceof JellyfinService)) {
                return false;
            }

            $ok = $media->terminateSession($sessionId, $reason);
            if ($ok) {
                Cache::forget('activity_snapshot_' . (int) $server->tenant_id);
            }

            return $ok;
        } catch (\Throwable $e) {
            Logger::warning('Stream limit kill failed', [
                'session_id' => $sessionId,
                'server_id' => (int) $server->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<int, array{id:int,uuid:string,server_id:?int,external_id:?string,username:string,display_name:?string,max_streams:mixed}>
     */
    private function loadMediaUsers(int $tenantId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, uuid, server_id, external_id, username, display_name, max_streams
             FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND status NOT IN (\'deleted\')',
            [$tenantId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'uuid' => (string) $r['uuid'],
            'server_id' => isset($r['server_id']) ? (int) $r['server_id'] : null,
            'external_id' => isset($r['external_id']) && $r['external_id'] !== '' ? (string) $r['external_id'] : null,
            'username' => (string) ($r['username'] ?? ''),
            'display_name' => isset($r['display_name']) && $r['display_name'] !== '' ? (string) $r['display_name'] : null,
            'max_streams' => $r['max_streams'],
        ], $rows);
    }

    /**
     * @param array<int, array{id:int,uuid:string,server_id:?int,external_id:?string,username:string,display_name:?string,max_streams:mixed}> $users
     * @return array{by_ext: array<string, array>, by_name: array<string, array>}
     */
    private function buildLookupMaps(array $users): array
    {
        $byExt = [];
        $byName = [];

        foreach ($users as $user) {
            $serverId = (int) ($user['server_id'] ?? 0);
            if ($serverId <= 0) {
                continue; // Unscoped users: skip to avoid killing wrong streams.
            }

            if ($user['external_id'] !== null) {
                $byExt[$serverId . ':' . $user['external_id']] = $user;
            }

            $uname = mb_strtolower(trim($user['username']));
            if ($uname !== '') {
                $byName[$serverId . ':' . $uname] = $user;
            }
            $dname = mb_strtolower(trim((string) ($user['display_name'] ?? '')));
            if ($dname !== '' && $dname !== $uname) {
                $byName[$serverId . ':' . $dname] = $user;
            }
        }

        return ['by_ext' => $byExt, 'by_name' => $byName];
    }

    /**
     * @param array{by_ext: array<string, array>, by_name: array<string, array>} $maps
     * @return array{id:int,uuid:string,server_id:?int,external_id:?string,username:string,display_name:?string,max_streams:mixed}|null
     */
    private function resolveMediaUser(array $session, array $maps): ?array
    {
        $serverId = (int) ($session['server_id'] ?? 0);
        if ($serverId <= 0) {
            return null;
        }

        $userId = trim((string) ($session['user_id'] ?? ''));
        if ($userId !== '') {
            $hit = $maps['by_ext'][$serverId . ':' . $userId] ?? null;
            if ($hit !== null) {
                return $hit;
            }
        }

        $name = mb_strtolower(trim((string) ($session['user'] ?? '')));
        if ($name === '') {
            return null;
        }

        return $maps['by_name'][$serverId . ':' . $name] ?? null;
    }

    /**
     * @param array<int, string> $sessionIds
     * @param array<int, string> $killedIds
     * @param array<int, array<string, string>> $titles
     */
    private function logViolation(
        int $tenantId,
        int $mediaUserId,
        ?int $serverId,
        string $username,
        int $streamCount,
        int $limit,
        array $sessionIds,
        array $killedIds,
        array $titles,
        string $message,
    ): void {
        try {
            Database::getInstance()->insert('stream_limit_violations', [
                'tenant_id' => $tenantId,
                'media_user_id' => $mediaUserId,
                'server_id' => $serverId,
                'username' => mb_substr($username, 0, 255),
                'stream_count' => $streamCount,
                'stream_limit' => $limit,
                'session_ids' => json_encode(array_values($sessionIds), JSON_UNESCAPED_UNICODE),
                'killed_session_ids' => json_encode(array_values($killedIds), JSON_UNESCAPED_UNICODE),
                'titles' => json_encode(array_values($titles), JSON_UNESCAPED_UNICODE),
                'action' => 'kill_newest',
                'message' => mb_substr($message, 0, 500),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Could not log stream limit violation', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listViolations(int $tenantId, int $limit = 100): array
    {
        self::ensureViolationsTable();

        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT v.*, mu.uuid AS media_user_uuid, mu.display_name, mu.email,
                        s.name AS server_name
                 FROM stream_limit_violations v
                 LEFT JOIN media_users mu ON mu.id = v.media_user_id
                 LEFT JOIN servers s ON s.id = v.server_id
                 WHERE v.tenant_id = ?
                 ORDER BY v.created_at DESC
                 LIMIT ?',
                [$tenantId, max(1, min(500, $limit))]
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static function (array $row): array {
            $titles = json_decode((string) ($row['titles'] ?? '[]'), true);
            $killed = json_decode((string) ($row['killed_session_ids'] ?? '[]'), true);

            return [
                'id' => (int) $row['id'],
                'at' => (string) $row['created_at'],
                'media_user_id' => isset($row['media_user_id']) ? (int) $row['media_user_id'] : null,
                'media_user_uuid' => (string) ($row['media_user_uuid'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'server_id' => isset($row['server_id']) ? (int) $row['server_id'] : null,
                'server_name' => (string) ($row['server_name'] ?? ''),
                'stream_count' => (int) $row['stream_count'],
                'stream_limit' => (int) $row['stream_limit'],
                'action' => (string) $row['action'],
                'titles' => is_array($titles) ? $titles : [],
                'killed_session_ids' => is_array($killed) ? $killed : [],
                'message' => (string) ($row['message'] ?? ''),
            ];
        }, $rows);
    }

    public static function ensureViolationsTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT 1 AS ok FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                ['stream_limit_violations']
            );
            if ($row !== null) {
                $ensured = true;
                return;
            }

            (new \Core\Updater())->runMigrations();
        } catch (\Throwable) {
            // ignore
        }

        $ensured = true;
    }
}
