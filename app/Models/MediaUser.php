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
 * @property int|null $on_server 1 = aparece en la lista del servidor, 0 = ausente, null = aún no sincronizado
 * @property string|null $membership_synced_at
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

    /** True if last force-sync found this external_id on the media server. */
    public function isOnServer(): bool
    {
        return (int) ($this->on_server ?? -1) === 1;
    }

    /** True if last force-sync confirmed the user is no longer on the server. */
    public function isMissingOnServer(): bool
    {
        return (int) ($this->on_server ?? -1) === 0;
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return strtotime($this->expires_at) < time();
    }

    /** @return array<string, mixed> */
    public function metaAll(): array
    {
        $raw = $this->metadata ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function metaGet(string $key, mixed $default = null): mixed
    {
        $meta = $this->metaAll();

        return $meta[$key] ?? $default;
    }

    public function metaSet(string $key, mixed $value): void
    {
        $meta = $this->metaAll();
        if ($value === null) {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }

        $this->metadata = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
    }
}
