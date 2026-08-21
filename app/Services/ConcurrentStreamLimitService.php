<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use App\Services\Media\SessionClientIp;
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
 * Default count mode: household — N teles en casa, 0 (o pagadas) fuera.
 * El corte solo si enforcement_enabled; si no, sandbox al admin (WhatsApp/Telegram).
 *
 * Logging: siempre se registra en stream_limit_violations al superar el límite
 * (con debounce por huella de sesiones/IPs). El corte solo si enforcement_enabled.
 */
final class ConcurrentStreamLimitService
{
    private const KILL_DEBOUNCE_TTL = 60;

    /**
     * Debounce de log: misma huella (IPs + session_ids + count/limit) no reinserta.
     * Se limpia al volver bajo el límite; TTL largo solo por higiene de caché.
     * Preferencia: registrar al cruzar el límite o cuando cambia el set de sesiones/IPs,
     * no en cada tick del cron (~5 min).
     */
    private const VIOLATION_FP_TTL = 86400;

    public function __construct(
        private StreamLimitSettingsService $settings = new StreamLimitSettingsService(),
    ) {
    }

    /**
     * Annotate sessions with media_user match / limit info, log over-limit violations,
     * and kill excess only if enforcement is enabled.
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
        $household = $countMode === StreamLimitSettingsService::COUNT_MODE_HOUSEHOLD;
        $distinctIp = $countMode === StreamLimitSettingsService::COUNT_MODE_DISTINCT_IP;
        $endpoints = new MediaUserEndpointService();

        // Attach media_user_id + limit + normalized IP key.
        foreach ($sessions as $i => $session) {
            $match = $this->resolveMediaUser($session, $maps);
            $sessions[$i]['media_user_id'] = $match['id'] ?? null;
            $sessions[$i]['media_user_uuid'] = $match['uuid'] ?? null;
            $sessions[$i]['stream_limit'] = $match !== null
                ? $this->settings->resolveLimitForUser($tenantId, $match['max_streams'] ?? null)
                : $defaultLimit;
            $sessions[$i]['home_limit'] = $match !== null
                ? $this->settings->resolveHomeLimitForUser($tenantId, $match['max_home_streams'] ?? null, $match['max_streams'] ?? null)
                : $defaultLimit;
            $sessions[$i]['away_limit'] = $match !== null
                ? $this->settings->resolveAwayLimitForUser($tenantId, $match['max_away_streams'] ?? null)
                : $this->settings->getDefaultMaxAwayStreams($tenantId);
            $sessions[$i]['over_limit'] = false;
            $sessions[$i]['would_cut'] = false;
            $sessions[$i]['cut_reason'] = '';
            $sessions[$i]['user_stream_count'] = 0;
            $sessions[$i]['client_ip'] = SessionClientIp::normalize((string) ($session['client_ip'] ?? ''));
            $sessions[$i]['ip_key'] = $this->ipKeyForSession($sessions[$i], $i);
            $sessions[$i]['household'] = 'away';
        }

        $matchedIds = [];
        foreach ($sessions as $session) {
            $uid = (int) ($session['media_user_id'] ?? 0);
            if ($uid > 0) {
                $matchedIds[$uid] = $uid;
            }
        }
        $homeIps = $endpoints->homeIpsByUserIds(array_values($matchedIds));

        foreach ($sessions as $i => $session) {
            $uid = (int) ($session['media_user_id'] ?? 0);
            $meta = $endpoints->classifyPlaybackMeta($session, $homeIps[$uid] ?? []);
            $sessions[$i]['household'] = $meta['kind'];
            $sessions[$i]['household_source'] = $meta['source'];
            $sessions[$i]['device_class'] = $meta['device_class'];
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
            $homeLimit = (int) ($sessions[$indexes[0]]['home_limit'] ?? $defaultLimit);
            $awayLimit = (int) ($sessions[$indexes[0]]['away_limit'] ?? 0);
            $serverId = (int) ($sessions[$indexes[0]]['server_id'] ?? 0);
            $username = (string) ($sessions[$indexes[0]]['user'] ?? '');

            $cutReasons = [];
            if ($household) {
                [$homeCount, $awayCount, $excessIndexes, $cutReasons] = $this->excessByHousehold(
                    $indexes,
                    $sessions,
                    $homeLimit,
                    $awayLimit
                );
                $count = $homeCount + $awayCount;
                $distinctIps = [];
            } elseif ($distinctIp) {
                [$count, $excessIndexes, $distinctIps] = $this->excessByDistinctIp($indexes, $sessions, $limit);
            } else {
                [$count, $excessIndexes, $distinctIps] = $this->excessBySessions($indexes, $sessions, $limit);
            }

            foreach ($indexes as $idx) {
                $sessions[$idx]['user_stream_count'] = $count;
                $sessions[$idx]['home_count'] = $household ? ($homeCount ?? 0) : $count;
                $sessions[$idx]['away_count'] = $household ? ($awayCount ?? 0) : 0;
                $sessions[$idx]['over_limit'] = in_array($idx, $excessIndexes, true);
                $sessions[$idx]['would_cut'] = in_array($idx, $excessIndexes, true);
                $sessions[$idx]['cut_reason'] = $cutReasons[$idx] ?? '';
                $sessions[$idx]['count_mode'] = $countMode;
            }

            if ($excessIndexes === []) {
                $this->clearViolationFingerprint((int) $mediaUserId);

                continue;
            }

            $killedIds = [];
            $titles = [];
            $allSessionIds = [];
            $allIps = [];

            // Snapshot completo de TODAS las reproducciones concurrentes (no solo las cortadas).
            foreach ($indexes as $idx) {
                $sid = (string) ($sessions[$idx]['session_id'] ?? '');
                $allSessionIds[] = $sid;
                $ip = (string) ($sessions[$idx]['client_ip'] ?? '');
                if ($ip !== '') {
                    $allIps[$ip] = true;
                }
                $titles[] = $this->sessionDetailForLog($sessions[$idx], false);
            }

            $clientIps = array_values(array_keys($allIps));
            if ($distinctIps !== []) {
                $clientIps = array_values(array_unique(array_merge($clientIps, $distinctIps)));
            }

            // Enforce (kick) solo si está activado; el log de incumplimiento va aparte.
            if ($enforce && $excessIndexes !== []) {
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

                    $reasonKey = (string) ($cutReasons[$idx] ?? $sessions[$idx]['cut_reason'] ?? '');
                    $sessionKillMessage = match ($reasonKey) {
                        'away' => $this->settings->getKillMessageAway($tenantId),
                        'home' => $this->settings->getKillMessageHome($tenantId),
                        default => $killMessage,
                    };
                    $ok = $this->terminateSession($server, $sessionId, $sessionKillMessage);

                    if ($ok) {
                        Cache::set($debounceKey, 1, self::KILL_DEBOUNCE_TTL);
                        $killIndexes[$idx] = true;
                        $killedIds[] = $sessionId;
                        $killedTotal++;
                    }
                }
            }

            // Marcar en el detalle qué sesiones se cortaron.
            if ($killedIds !== []) {
                $killedSet = array_fill_keys($killedIds, true);
                foreach ($titles as $ti => $detail) {
                    $sid = (string) ($detail['session_id'] ?? '');
                    $titles[$ti]['killed'] = $sid !== '' && isset($killedSet[$sid]);
                }
            }

            $fingerprint = $this->violationFingerprint(
                $allSessionIds,
                $clientIps,
                $count,
                $household ? $homeLimit : $limit,
                $household ? 'household' : ($distinctIp ? 'distinct_ip' : 'sessions')
            );

            if (!$this->shouldLogViolation((int) $mediaUserId, $fingerprint)) {
                continue;
            }

            $violations++;
            $action = $killedIds !== []
                ? ($distinctIp ? 'kill_newest_ips' : 'kill_newest')
                : 'detected';
            $message = $killedIds !== []
                ? $killMessage
                : ($enforce
                    ? 'Exceso detectado (corte no aplicado o fallido).'
                    : 'Exceso detectado (aplicación automática desactivada).');

            $this->logViolation(
                $tenantId,
                (int) $mediaUserId,
                $serverId > 0 ? $serverId : null,
                $username,
                $count,
                $limit,
                $allSessionIds,
                $killedIds,
                $titles,
                $message,
                $action,
                $clientIps
            );

            try {
                (new \App\Services\Notifications\AdminCriticalAlertService())->notifyStreamLimitViolation(
                    $tenantId,
                    $username,
                    $count,
                    $household ? $homeLimit : $limit,
                    $killedIds !== [],
                    $fingerprint,
                    $titles,
                    [
                        'enforced' => $killedIds !== [],
                        'sandbox' => $killedIds === [],
                        'home_count' => $household ? (int) ($homeCount ?? 0) : $count,
                        'away_count' => $household ? (int) ($awayCount ?? 0) : 0,
                        'home_limit' => $homeLimit,
                        'away_limit' => $awayLimit,
                        'household' => $household,
                    ]
                );
            } catch (\Throwable) {
                // No bloquear el corte/registro si falla el aviso admin.
            }

            AuditService::log(
                $killedIds !== [] ? 'media_user.stream_limit_enforced' : 'media_user.stream_limit_detected',
                'media_user',
                (int) $mediaUserId,
                null,
                [
                    'stream_count' => $count,
                    'limit' => $limit,
                    'count_mode' => $countMode,
                    'client_ips' => $clientIps,
                    'sessions' => $titles,
                    'killed_session_ids' => $killedIds,
                    'action' => $action,
                    'server_id' => $serverId,
                    'enforcement_enabled' => $enforce,
                ],
                null,
                $tenantId
            );
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
                $homeLimit = (int) ($sessions[$indexes[0]]['home_limit'] ?? $defaultLimit);
                $awayLimit = (int) ($sessions[$indexes[0]]['away_limit'] ?? 0);
                if ($household) {
                    [$homeCount, $awayCount, $excessLeft] = $this->excessByHousehold($indexes, $sessions, $homeLimit, $awayLimit);
                    $count = $homeCount + $awayCount;
                    foreach ($indexes as $idx) {
                        $sessions[$idx]['user_stream_count'] = $count;
                        $sessions[$idx]['home_count'] = $homeCount;
                        $sessions[$idx]['away_count'] = $awayCount;
                        $sessions[$idx]['over_limit'] = in_array($idx, $excessLeft, true);
                        $sessions[$idx]['would_cut'] = false;
                    }
                } elseif ($distinctIp) {
                    [$count] = $this->excessByDistinctIp($indexes, $sessions, $limit);
                    foreach ($indexes as $idx) {
                        $sessions[$idx]['user_stream_count'] = $count;
                        $sessions[$idx]['over_limit'] = $count > $limit;
                    }
                } else {
                    $count = count($indexes);
                    foreach ($indexes as $idx) {
                        $sessions[$idx]['user_stream_count'] = $count;
                        $sessions[$idx]['over_limit'] = $count > $limit;
                    }
                }
            }
        }

        try {
            (new MediaUserEndpointService())->recordFromSessions($tenantId, $sessions);
        } catch (\Throwable) {
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
     * @return array{0: int, 1: int, 2: array<int, int>, 3: array<int, string>} homeCount, awayCount, excess indexes, reasons
     */
    private function excessByHousehold(array $indexes, array $sessions, int $homeLimit, int $awayLimit): array
    {
        $home = [];
        $away = [];
        foreach ($indexes as $idx) {
            if (($sessions[$idx]['household'] ?? 'away') === MediaUserEndpointService::KIND_HOME) {
                $home[] = $idx;
            } else {
                $away[] = $idx;
            }
        }

        $excess = [];
        $reasons = [];
        if (count($away) > $awayLimit) {
            foreach (array_slice($away, $awayLimit) as $idx) {
                $excess[] = $idx;
                $reasons[$idx] = 'away';
            }
        }
        if (count($home) > $homeLimit) {
            foreach (array_slice($home, $homeLimit) as $idx) {
                $excess[] = $idx;
                $reasons[$idx] = 'home';
            }
        }

        return [count($home), count($away), $excess, $reasons];
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
     * Cron entry: fetch live sessions, log over-limit violations, kill only if enforce ON.
     *
     * @return array{checked: int, killed: int, violations: int}
     */
    public function runForTenant(int $tenantId): array
    {
        // Siempre detectar/registrar incumplimientos; el corte solo ocurre si enforce está ON
        // (dentro de enforceAndAnnotate → getSnapshot).
        Cache::forget('activity_snapshot_' . $tenantId);
        $snapshot = (new StreamingActivityService())->getSnapshot($tenantId);

        return [
            'checked' => (int) ($snapshot['total_count'] ?? count($snapshot['sessions'] ?? [])),
            'killed' => (int) ($snapshot['stream_limit_killed'] ?? 0),
            'violations' => (int) ($snapshot['stream_limit_violations'] ?? 0),
        ];
    }

    /**
     * Detalle completo de una reproducción para el log de incumplimiento.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function sessionDetailForLog(array $session, bool $killed): array
    {
        return [
            'title' => (string) ($session['title'] ?? ''),
            'subtitle' => (string) ($session['subtitle'] ?? ''),
            'player' => (string) ($session['player'] ?? ''),
            'product' => (string) ($session['product'] ?? ''),
            'platform' => (string) ($session['platform'] ?? ''),
            'state' => (string) ($session['state'] ?? ''),
            'server' => (string) ($session['server_name'] ?? ''),
            'session_id' => (string) ($session['session_id'] ?? ''),
            'ip' => (string) ($session['client_ip'] ?? ''),
            'progress' => (int) ($session['progress'] ?? 0),
            'play_method' => (string) ($session['play_method'] ?? ''),
            'media_type' => (string) ($session['media_type'] ?? ''),
            'location' => (string) ($session['location'] ?? ''),
            'bandwidth' => (string) ($session['bandwidth'] ?? ''),
            'household' => (string) ($session['household'] ?? ''),
            'cut_reason' => (string) ($session['cut_reason'] ?? ''),
            'would_cut' => !empty($session['would_cut']) || $killed,
            'killed' => $killed,
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
        self::ensureHomeAwayColumns();
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, uuid, server_id, external_id, username, display_name, max_streams, max_home_streams, max_away_streams
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
            'max_streams' => $r['max_streams'] ?? null,
            'max_home_streams' => $r['max_home_streams'] ?? null,
            'max_away_streams' => $r['max_away_streams'] ?? null,
        ], $rows);
    }

    private static function ensureHomeAwayColumns(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT 1 AS ok FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_users' AND COLUMN_NAME = 'max_home_streams' LIMIT 1"
            );
            if ($row === null) {
                (new \Core\Updater())->runMigrations();
            }
        } catch (\Throwable) {
        }
        $ensured = true;
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
     * @param array<int, string> $clientIps
     */

    /**
     * Huella estable del incumplimiento actual (ordenada).
     *
     * @param array<int, string> $sessionIds
     * @param array<int, string> $clientIps
     */
    private function violationFingerprint(
        array $sessionIds,
        array $clientIps,
        int $count,
        int $limit,
        string $countMode,
    ): string {
        $sessions = array_values(array_filter(array_map('strval', $sessionIds), static fn (string $s): bool => $s !== ''));
        $ips = array_values(array_filter(array_map('strval', $clientIps), static fn (string $s): bool => $s !== ''));
        sort($sessions);
        sort($ips);

        return hash('sha256', json_encode([
            'sessions' => $sessions,
            'ips' => $ips,
            'count' => $count,
            'limit' => $limit,
            'mode' => $countMode,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function shouldLogViolation(int $mediaUserId, string $fingerprint): bool
    {
        $key = 'stream_limit_vio_fp_' . $mediaUserId;
        $prev = Cache::get($key);
        if (is_string($prev) && $prev === $fingerprint) {
            return false;
        }
        Cache::set($key, $fingerprint, self::VIOLATION_FP_TTL);

        return true;
    }

    private function clearViolationFingerprint(int $mediaUserId): void
    {
        Cache::forget('stream_limit_vio_fp_' . $mediaUserId);
    }

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
        string $action = 'kill_newest_ips',
        array $clientIps = [],
    ): void {
        try {
            self::ensureClientIpsColumn();
            $row = [
                'tenant_id' => $tenantId,
                'media_user_id' => $mediaUserId,
                'server_id' => $serverId,
                'username' => mb_substr($username, 0, 255),
                'stream_count' => $streamCount,
                'stream_limit' => $limit,
                'session_ids' => json_encode(array_values($sessionIds), JSON_UNESCAPED_UNICODE),
                'killed_session_ids' => json_encode(array_values($killedIds), JSON_UNESCAPED_UNICODE),
                'titles' => json_encode(array_values($titles), JSON_UNESCAPED_UNICODE),
                'action' => $action,
                'message' => mb_substr($message, 0, 500),
            ];
            if (self::hasClientIpsColumn()) {
                $row['client_ips'] = json_encode(array_values($clientIps), JSON_UNESCAPED_UNICODE);
            }
            Database::getInstance()->insert('stream_limit_violations', $row);
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
            $ips = json_decode((string) ($row['client_ips'] ?? '[]'), true);
            if (!is_array($ips) || $ips === []) {
                $ips = [];
                if (is_array($titles)) {
                    foreach ($titles as $t) {
                        $ip = trim((string) ($t['ip'] ?? ''));
                        if ($ip !== '') {
                            $ips[$ip] = true;
                        }
                    }
                    $ips = array_keys($ips);
                }
            }

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
                'client_ips' => is_array($ips) ? array_values($ips) : [],
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
            if ($row === null) {
                (new \Core\Updater())->runMigrations();
            }
            self::ensureClientIpsColumn();
        } catch (\Throwable) {
            // ignore
        }

        $ensured = true;
    }

    private static function ensureClientIpsColumn(): void
    {
        if (self::hasClientIpsColumn()) {
            return;
        }

        try {
            (new \Core\Updater())->runMigrations();
        } catch (\Throwable) {
            // ignore
        }

        if (self::hasClientIpsColumn()) {
            return;
        }

        try {
            Database::getInstance()->pdo()->exec(
                'ALTER TABLE `stream_limit_violations` ADD COLUMN `client_ips` JSON NULL'
            );
        } catch (\Throwable) {
            // ignore duplicate/missing table
        }
    }

    private static function hasClientIpsColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT 1 AS ok FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                ['stream_limit_violations', 'client_ips']
            );
            $has = $row !== null;
        } catch (\Throwable) {
            $has = false;
        }

        return $has;
    }
}
