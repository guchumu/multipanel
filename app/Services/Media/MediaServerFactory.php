<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Server;
use App\Services\Media\PlexService;
use App\Services\Media\JellyfinService;

/**
 * Factory for media server integrations.
 */
final class MediaServerFactory
{
    public static function make(Server $server, bool $quick = false): PlexService|JellyfinService
    {
        return match ($server->type) {
            'plex' => new PlexService($server, $quick),
            'jellyfin' => new JellyfinService($server, $quick),
            default => throw new \InvalidArgumentException("Unsupported server type: {$server->type}"),
        };
    }
}
