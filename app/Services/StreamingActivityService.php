<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use App\Services\Notifications\AdminCriticalAlertService;
use Core\Cache;
use Core\Logger;

/**
 * Aggregates live playback sessions from all media servers.
 */
final class StreamingActivityService
{
    /**
     * El snapshot consulta todos los servidores en serie (con timeouts que
     * pueden sumar decenas de segundos si alguno está caído). Se cachea unos
     * segundos para que el polling de "En directo", el listado de servidores
     * y las fichas de usuario no repitan ese coste en cada petición.
     */
    private const SNAPSHOT_CACHE_TTL = 30;

    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
        private ServerSyncService $sync = new ServerSyncService(),
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getLiveSessions(int $tenantId, ?int $serverId = null): array
    {
        return $this->getSnapshot($tenantId, $serverId)['sessions'];
    }

    /**
     * Sesiones activas de un usuario media concreto (para mostrar en su ficha
     * qué está viendo ahora mismo, con carátula). Se compara por nombre de
     * usuario/nombre visible ya que Plex/Jellyfin no siempre exponen el email.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSessionsForUser(int $tenantId, int $serverId, string $username, ?string $displayName = null): array
    {
        // Solo consultamos el servidor del usuario, no todos los del tenant:
        // esta llamada se hace al abrir cada ficha de usuario y no debe pagar
        // el coste del snapshot completo.
        $server = Server::find($serverId);
        if ($server === null || (int) $server->tenant_id !== $tenantId) {
            return [];
        }

        $sessions = $this->fetchServerSessions($server);
        $needles = array_filter(array_map(
            static fn (?string $v): string => mb_strtolower(trim((string) $v)),
            [$username, $displayName]
        ));

        if ($needles === []) {
            return [];
        }

        return array_values(array_filter($sessions, static function (array $session) use ($needles): bool {
            $sessionUser = mb_strtolower(trim((string) ($session['user'] ?? '')));

            return $sessionUser !== '' && in_array($sessionUser, $needles, true);
        }));
    }

    /**
     * @return array{
     *     sessions: array<int, array<string, mixed>>,
     *     grouped: array<int, array{server_id: int, server_name: string, server_type: string, sessions: array<int, array<string, mixed>>}>,
     *     server_stats: array<int, array{id: int, name: string, type: string, status: string, count: int}>,
     *     total_count: int,
     *     filtered_count: int
     * }
     */
    public function getSnapshot(int $tenantId, ?int $serverId = null): array
    {
        $cacheKey = 'activity_snapshot_' . $tenantId;
        $cached = Cache::get($cacheKey);
        $streamLimitKilled = 0;
        $streamLimitViolations = 0;

        if (is_array($cached) && isset($cached['sessions'], $cached['server_stats'])) {
            $allSessions = $cached['sessions'];
            $serverStats = $cached['server_stats'];
            $streamLimitKilled = (int) ($cached['stream_limit_killed'] ?? 0);
            $streamLimitViolations = (int) ($cached['stream_limit_violations'] ?? 0);
        } else {
            $allSessions = [];
            $serverStats = [];

            foreach ($this->servers->allByTenant($tenantId) as $server) {
                $serverSessions = $this->fetchServerSessions($server);
                $liveStatus = (string) $server->status;
                if ($liveStatus !== 'online' && $serverSessions !== []) {
                    $liveStatus = 'online';
                }
                $serverStats[] = [
                    'id' => (int) $server->id,
                    'name' => (string) $server->name,
                    'type' => (string) $server->type,
                    'status' => $liveStatus,
                    'count' => count($serverSessions),
                ];
                $allSessions = array_merge($allSessions, $serverSessions);
            }

            // Anotar media_user / límite, registrar incumplimientos y cortar solo si enforce está activo.
            $limitResult = (new ConcurrentStreamLimitService())->enforceAndAnnotate($tenantId, $allSessions);
            $allSessions = $limitResult['sessions'];
            $streamLimitKilled = (int) $limitResult['killed'];
            $streamLimitViolations = (int) $limitResult['violations'];

            // Recalcular contadores por servidor tras posibles cortes.
            $countsByServer = [];
            foreach ($allSessions as $session) {
                $sid = (int) ($session['server_id'] ?? 0);
                $countsByServer[$sid] = ($countsByServer[$sid] ?? 0) + 1;
            }
            foreach ($serverStats as $i => $stat) {
                $serverStats[$i]['count'] = $countsByServer[(int) $stat['id']] ?? 0;
            }

            Cache::set($cacheKey, [
                'sessions' => $allSessions,
                'server_stats' => $serverStats,
                'stream_limit_killed' => $streamLimitKilled,
                'stream_limit_violations' => $streamLimitViolations,
            ], self::SNAPSHOT_CACHE_TTL);
        }

        $filtered = $serverId !== null
            ? array_values(array_filter($allSessions, fn (array $session): bool => (int) $session['server_id'] === $serverId))
            : $allSessions;

        $grouped = [];
        if ($serverId === null) {
            foreach ($allSessions as $session) {
                $sid = (int) $session['server_id'];
                if (!isset($grouped[$sid])) {
                    $grouped[$sid] = [
                        'server_id' => $sid,
                        'server_name' => (string) $session['server_name'],
                        'server_type' => (string) $session['server_type'],
                        'sessions' => [],
                    ];
                }
                $grouped[$sid]['sessions'][] = $session;
            }
            $grouped = array_values($grouped);
        }

        return [
            'sessions' => $filtered,
            'grouped' => $grouped,
            'server_stats' => $serverStats,
            'total_count' => count($allSessions),
            'filtered_count' => count($filtered),
            'stream_limit_killed' => $streamLimitKilled,
            'stream_limit_violations' => $streamLimitViolations,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchServerSessions(Server $server): array
    {
        try {
            $media = MediaServerFactory::make($server, true);
            $raw = $media->getActiveSessions();

            $reachable = !($media instanceof PlexService && $media->getLastError() !== null);
            if ($reachable) {
                $this->sync->touchOnline($server, count($raw));
            }

            return array_map(function (array $session) use ($server) {
                $session['server_id'] = (int) $server->id;
                $session['server_uuid'] = (string) $server->uuid;
                $session['server_name'] = (string) $server->name;
                $session['server_type'] = (string) $server->type;
                return $this->enrichSessionForPanel($session, $server);
            }, $raw);
        } catch (\Throwable) {
            return [];
        }
    }

    public function terminateSession(Server $server, string $sessionId, ?string $reason = null): bool
    {
        $media = MediaServerFactory::make($server);

        if (!($media instanceof PlexService || $media instanceof JellyfinService)) {
            return false;
        }

        $ok = $media->terminateSession($sessionId, $reason);
        if ($ok) {
            // Evitar que el polling siga mostrando la sesión cortada unos segundos.
            Cache::forget('activity_snapshot_' . (int) $server->tenant_id);
        }

        return $ok;
    }

    /**
     * True solo si la línea Video es Transcode (lo que ve el usuario en En directo).
     * No corta Direct Play / Direct Stream / copy, ni «Converting» del contenedor, ni solo-audio.
     *
     * @param array<string, mixed> $session
     */
    public static function isVideoTranscodeSession(array $session): bool
    {
        $decision = strtolower(trim((string) ($session['video_decision'] ?? '')));
        if ($decision === 'transcode') {
            return true;
        }

        // Jellyfin a veces guarda el codec ("h264") en video_decision; Plex a veces deja
        // video_decision vacío y rellena stream_info.video. Confiar en la línea Video.
        $info = is_array($session['stream_info'] ?? null) ? $session['stream_info'] : [];
        $line = strtolower(trim((string) ($info['video'] ?? $session['video_label'] ?? '')));
        if ($line === '') {
            return false;
        }

        // Solo al inicio: "Transcode (...)", nunca "Converting" (contenedor).
        return str_starts_with($line, 'transcode');
    }

    /**
     * Corta ahora todas las sesiones con vídeo en Transcode.
     * Avisa al admin, envía el mensaje al detener y corta tras ~10 s.
     *
     * @return array{killed: int, failed: int, matched: int}
     */
    public function killVideoTranscodes(int $tenantId, ?int $serverId = null, ?string $message = null): array
    {
        Cache::forget('activity_snapshot_' . $tenantId);
        $sessions = $this->getSnapshot($tenantId, $serverId)['sessions'] ?? [];
        $message = trim((string) $message);
        if ($message === '') {
            $message = (new PlaybackStopMessageService())->defaultBody($tenantId);
        }

        $matched = 0;
        $killed = 0;
        $failed = 0;

        foreach ($sessions as $session) {
            if (!self::isVideoTranscodeSession($session)) {
                continue;
            }
            $matched++;
            $sessionId = trim((string) ($session['session_id'] ?? ''));
            $sid = (int) ($session['server_id'] ?? 0);
            if ($sessionId === '' || $sid <= 0) {
                $failed++;
                continue;
            }
            $server = Server::find($sid);
            if ($server === null || (int) $server->tenant_id !== $tenantId) {
                $failed++;
                continue;
            }
            if ($this->terminateVideoTranscodeSession($tenantId, $server, $session, $sessionId, $message, true)) {
                $killed++;
            } else {
                $failed++;
            }
        }

        return ['killed' => $killed, 'failed' => $failed, 'matched' => $matched];
    }

    /**
     * Si el auto-corte está activo (cron streams): notifica admin → mensaje → ~10 s → corta.
     *
     * @param array<int, array<string, mixed>>|null $sessions Sesiones ya obtenidas; null = snapshot fresco
     * @return array{killed: int, failed: int, skipped: int, matched: int, enabled: bool}
     */
    public function autoKillVideoTranscodesIfEnabled(int $tenantId, ?array $sessions = null): array
    {
        $settings = new StreamLimitSettingsService();
        if (!$settings->isAutoKillVideoTranscodesEnabled($tenantId)) {
            return ['killed' => 0, 'failed' => 0, 'skipped' => 0, 'matched' => 0, 'enabled' => false];
        }

        if ($sessions === null) {
            Cache::forget('activity_snapshot_' . $tenantId);
            $sessions = $this->getSnapshot($tenantId)['sessions'] ?? [];
        }

        $message = (new PlaybackStopMessageService())->defaultBody($tenantId);
        $killed = 0;
        $failed = 0;
        $skipped = 0;
        $matched = 0;

        foreach ($sessions as $session) {
            if (!self::isVideoTranscodeSession($session)) {
                continue;
            }
            $matched++;
            $sessionId = trim((string) ($session['session_id'] ?? ''));
            $serverId = (int) ($session['server_id'] ?? 0);
            if ($sessionId === '' || $serverId <= 0) {
                $skipped++;
                continue;
            }

            $debounceKey = 'auto_kill_vtrans_' . $serverId . '_' . sha1($sessionId);
            if (Cache::get($debounceKey)) {
                $skipped++;
                continue;
            }

            $server = Server::find($serverId);
            if ($server === null || (int) $server->tenant_id !== $tenantId) {
                $failed++;
                continue;
            }

            // Marcar ya: evita notify+kill en cada tick del cron para la misma sesión.
            Cache::set($debounceKey, 1, 120);

            $ok = $this->terminateVideoTranscodeSession(
                $tenantId,
                $server,
                $session,
                $sessionId,
                $message,
                true
            );
            if ($ok) {
                $killed++;
            } else {
                $failed++;
            }
        }

        return [
            'killed' => $killed,
            'failed' => $failed,
            'skipped' => $skipped,
            'matched' => $matched,
            'enabled' => true,
        ];
    }

    /**
     * Orden: avisar admin (Telegram/WhatsApp/ntfy/email según canales críticos) →
     * mensaje al reproductor / preparar corte → ~10 s → terminar sesión.
     *
     * @param array<string, mixed> $session
     */
    private function terminateVideoTranscodeSession(
        int $tenantId,
        Server $server,
        array $session,
        string $sessionId,
        string $message,
        bool $notifyAdmin,
    ): bool {
        if ($notifyAdmin) {
            $username = trim((string) ($session['user'] ?? '')) ?: 'desconocido';
            $title = trim((string) ($session['title'] ?? '')) ?: 'Sin título';
            $serverName = trim((string) ($server->name ?? '')) ?: ('#' . (int) $server->id);
            $fp = (int) $server->id . ':' . sha1($sessionId);
            try {
                (new AdminCriticalAlertService())->notifyVideoTranscodeKill(
                    $tenantId,
                    $username,
                    $title,
                    $serverName,
                    $fp
                );
            } catch (\Throwable $e) {
                Logger::warning('Video transcode admin notify failed', [
                    'tenant_id' => $tenantId,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $media = MediaServerFactory::make($server);
        // Plex muestra el motivo al cortar; Jellyfin ya espera ~10 s tras el mensaje en terminateSession.
        if ($media instanceof PlexService) {
            usleep(10_000_000);
        }

        return $this->terminateSession($server, $sessionId, $message);
    }

    /** @return array{body: string, content_type: string}|null */
    public function fetchArtwork(Server $server, ?string $artPath = null, ?string $itemId = null): ?array
    {
        $media = MediaServerFactory::make($server);

        if ($media instanceof PlexService && $artPath !== null && $artPath !== '') {
            return $media->fetchArtwork($artPath);
        }

        if ($media instanceof JellyfinService && $itemId !== null && $itemId !== '') {
            return $media->fetchItemImage($itemId);
        }

        return null;
    }

    /** @param array<string, mixed> $session */
    private function enrichSessionForPanel(array $session, Server $server): array
    {
        // Siempre proxificar: nunca devolver URL directa http://Plex al navegador
        // (mixed content en HTTPS + token expuesto). Usamos ?p= base64url para
        // evitar que WAFs/Apache alteren %2F en ?path=/library/...
        // Formato idéntico a ActivityController::thumbsDebug → proxy_url.
        $session['server_uuid'] = (string) $server->uuid;

        if (!empty($session['art_path'])) {
            $session['thumb_url'] = '/activity/thumb/' . (string) $server->uuid
                . '?p=' . self::encodeThumbParam((string) $session['art_path']);
        } elseif (!empty($session['item_id'])) {
            $session['thumb_url'] = '/activity/thumb/' . (string) $server->uuid
                . '?item=' . rawurlencode((string) $session['item_id']);
        } else {
            $session['thumb_url'] = '';
        }

        $session['video_label'] = $this->decisionLabel((string) ($session['video_decision'] ?? ''));
        $session['audio_label'] = $this->decisionLabel((string) ($session['audio_decision'] ?? ''));
        $session['can_kill'] = !empty($session['session_id']);

        // Asegurar estructura stream_info para la tarjeta En directo (PHP + JS).
        if (!isset($session['stream_info']) || !is_array($session['stream_info'])) {
            $session['stream_info'] = [
                'quality' => '—',
                'stream' => $this->playMethodDisplay((string) ($session['play_method'] ?? '')),
                'container' => '—',
                'video' => (string) ($session['video_label'] ?? '—'),
                'audio' => (string) ($session['audio_label'] ?? '—'),
                'subtitle' => 'None',
                'throttled' => false,
            ];
        }

        return $session;
    }

    private function playMethodDisplay(string $method): string
    {
        return match ($method) {
            'direct_play' => 'Direct Play',
            'direct_stream' => 'Direct Stream',
            'transcode' => 'Transcode',
            default => $method !== '' ? ucfirst(str_replace('_', ' ', $method)) : 'Direct Play',
        };
    }

    /** Codifica un path de carátula en base64url (sin padding). */
    public static function encodeThumbParam(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Decodifica ?p= base64url; null si no es válido. */
    public static function decodeThumbParam(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        return $decoded;
    }

    private function decisionLabel(string $decision): string
    {
        $decision = strtolower(trim($decision));

        return match ($decision) {
            'copy', 'directplay', 'direct play' => 'Direct',
            'transcode', '' => $decision === '' ? 'Direct' : 'Transcode',
            default => ucfirst($decision),
        };
    }
}
