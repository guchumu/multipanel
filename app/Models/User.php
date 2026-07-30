<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Panel user model.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $uuid
 * @property int $role_id
 * @property string $email
 * @property string $username
 * @property string $password
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $status
 */
class User extends Model
{
    protected static string $table = 'users';

    public function fullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: $this->username;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role_id, [1, 2], true);
    }
}
