<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use Core\Cache;

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
    private const SNAPSHOT_CACHE_TTL = 15;

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

        if (is_array($cached) && isset($cached['sessions'], $cached['server_stats'])) {
            $allSessions = $cached['sessions'];
            $serverStats = $cached['server_stats'];
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

            Cache::set($cacheKey, [
                'sessions' => $allSessions,
                'server_stats' => $serverStats,
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
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchServerSessions(Server $server): array
    {
        try {
            $media = MediaServerFactory::make($server);
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

    public function terminateSession(Server $server, string $sessionId): bool
    {
        $media = MediaServerFactory::make($server);

        if ($media instanceof PlexService || $media instanceof JellyfinService) {
            return $media->terminateSession($sessionId);
        }

        return false;
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

        return $session;
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
