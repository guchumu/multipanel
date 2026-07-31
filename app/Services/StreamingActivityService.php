<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
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
            $serverStats[] = [
                'id' => (int) $server->id,
                'name' => (string) $server->name,
                'type' => (string) $server->type,
                'status' => (string) $server->status,
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

            if ($media instanceof PlexService && $media->getLastError() === null) {
                $this->sync->touchOnline($server, count($raw));
            } elseif ($raw !== []) {
                $this->sync->touchOnline($server, count($raw));
            }

            return array_map(function (array $session) use ($server) {
                $session['server_id'] = (int) $server->id;
                $session['server_uuid'] = (string) $server->uuid;
                $session['server_name'] = (string) $server->name;
                $session['server_type'] = (string) $server->type;
                return $session;
            }, $raw);
        } catch (\Throwable) {
            return [];
        }
    }
}
