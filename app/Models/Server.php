<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Media\ServerEndpoint;
use Core\Model;

/**
 * Media server model (Plex/Jellyfin).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $uuid
 * @property string $name
 * @property string $type
 * @property string $url
 * @property int $port
 * @property string $status
 */
class Server extends Model
{
    protected static string $table = 'servers';

    public function fullUrl(): string
    {
        $endpoint = ServerEndpoint::normalize(
            (string) $this->url,
            (int) ($this->port ?: 32400),
            (bool) $this->ssl
        );
        $scheme = $endpoint['ssl'] ? 'https' : 'http';
        return "{$scheme}://{$endpoint['url']}:{$endpoint['port']}";
    }

    public function displayHost(): string
    {
        $endpoint = ServerEndpoint::normalize(
            (string) $this->url,
            (int) ($this->port ?: 32400),
            (bool) $this->ssl
        );

        return $endpoint['url'];
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function isPlex(): bool
    {
        return $this->type === 'plex';
    }

    public function isJellyfin(): bool
    {
        return $this->type === 'jellyfin';
    }
}
