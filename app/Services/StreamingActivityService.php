<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;

/**
 * Aggregates live playback sessions from all media servers.
 */
final class StreamingActivityService
{
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
        $sessions = $this->getSnapshot($tenantId, $serverId)['sessions'];
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
        if (!empty($session['art_path'])) {
            $session['thumb_url'] = '/activity/thumb/' . (string) $server->uuid
                . '?path=' . rawurlencode((string) $session['art_path']);
        } elseif (!empty($session['item_id'])) {
            $session['thumb_url'] = '/activity/thumb/' . (string) $server->uuid
                . '?item=' . rawurlencode((string) $session['item_id']);
        }

        $session['video_label'] = $this->decisionLabel((string) ($session['video_decision'] ?? ''));
        $session['audio_label'] = $this->decisionLabel((string) ($session['audio_decision'] ?? ''));
        $session['can_kill'] = !empty($session['session_id']);

        return $session;
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
