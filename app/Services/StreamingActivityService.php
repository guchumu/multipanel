<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\MediaServerFactory;

/**
 * Aggregates live playback sessions from all media servers.
 */
final class StreamingActivityService
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getLiveSessions(int $tenantId, ?int $serverId = null): array
    {
        $sessions = [];

        foreach ($this->servers->allByTenant($tenantId) as $server) {
            if ($serverId !== null && (int) $server->id !== $serverId) {
                continue;
            }

            $sessions = array_merge($sessions, $this->fetchServerSessions($server));
        }

        return $sessions;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchServerSessions(Server $server): array
    {
        try {
            $media = MediaServerFactory::make($server);
            $raw = $media->getActiveSessions();

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
