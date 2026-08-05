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
 * Policy when over limit: keep the N sessions with highest progress (primary watch),
 * kill the newest/excess (lowest progress, then later in list). Documented choice:
 * "kill newest beyond limit so primary watch continues".
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

        // Attach media_user_id + limit to each session.
        foreach ($sessions as $i => $session) {
            $match = $this->resolveMediaUser($session, $maps);
            $sessions[$i]['media_user_id'] = $match['id'] ?? null;
            $sessions[$i]['media_user_uuid'] = $match['uuid'] ?? null;
            $sessions[$i]['stream_limit'] = $match !== null
                ? $this->settings->resolveLimitForUser($tenantId, $match['max_streams'] ?? null)
                : $defaultLimit;
            $sessions[$i]['over_limit'] = false;
            $sessions[$i]['user_stream_count'] = 0;
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
            $count = count($indexes);

            foreach ($indexes as $idx) {
                $sessions[$idx]['user_stream_count'] = $count;
                $sessions[$idx]['over_limit'] = $count > $limit;
            }

            if (!$enforce || $count <= $limit) {
                continue;
            }

            // Sort keep-first: highest progress, then earlier index (older in API list).
            usort($indexes, static function (int $a, int $b) use ($sessions): int {
                $pa = (int) ($sessions[$a]['progress'] ?? 0);
                $pb = (int) ($sessions[$b]['progress'] ?? 0);
                if ($pa !== $pb) {
                    return $pb <=> $pa; // higher progress first (keep)
                }

                return $a <=> $b; // stable / older-in-list first
            });

            $keep = array_slice($indexes, 0, $limit);
            $excess = array_slice($indexes, $limit);
            unset($keep);

            $killedIds = [];
            $titles = [];
            $allSessionIds = [];
            $serverId = (int) ($sessions[$indexes[0]]['server_id'] ?? 0);
            $username = (string) ($sessions[$indexes[0]]['user'] ?? '');

            foreach ($indexes as $idx) {
                $allSessionIds[] = (string) ($sessions[$idx]['session_id'] ?? '');
                $titles[] = [
                    'title' => (string) ($sessions[$idx]['title'] ?? ''),
                    'player' => (string) ($sessions[$idx]['player'] ?? ''),
                    'server' => (string) ($sessions[$idx]['server_name'] ?? ''),
                    'session_id' => (string) ($sessions[$idx]['session_id'] ?? ''),
                ];
            }

            foreach ($excess as $idx) {
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
                    $killMessage
                );

                AuditService::log(
                    'media_user.stream_limit_enforced',
                    'media_user',
                    $mediaUserId,
                    null,
                    [
                        'stream_count' => $count,
                        'limit' => $limit,
                        'killed_session_ids' => $killedIds,
                        'action' => 'kill_newest',
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

            // Recalculate counts after removals.
            $recount = [];
            foreach ($sessions as $i => $session) {
                $uid = $session['media_user_id'] ?? null;
                if ($uid === null) {
                    continue;
                }
                $recount[(int) $uid] = ($recount[(int) $uid] ?? 0) + 1;
            }
            foreach ($sessions as $i => $session) {
                $uid = $session['media_user_id'] ?? null;
                if ($uid === null) {
                    continue;
                }
                $c = $recount[(int) $uid] ?? 0;
                $limit = (int) ($session['stream_limit'] ?? $defaultLimit);
                $sessions[$i]['user_stream_count'] = $c;
                $sessions[$i]['over_limit'] = $c > $limit;
            }
        }

        return ['sessions' => $sessions, 'killed' => $killedTotal, 'violations' => $violations];
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
