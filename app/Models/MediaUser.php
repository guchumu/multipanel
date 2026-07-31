<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Managed Plex/Jellyfin user account.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $uuid
 * @property int|null $server_id
 * @property string $username
 * @property string $status
 * @property string|null $expires_at
 * @property string|null $telegram_chat_id
 */
class MediaUser extends Model
{
    protected static string $table = 'media_users';

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return strtotime($this->expires_at) < time();
    }
}
