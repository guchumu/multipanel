<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Core\Database;

/**
 * Attribute-Based Access Control — tenant and resource isolation.
 */
final class AbacService
{
    public function belongsToTenant(array $resource, int $tenantId, string $field = 'tenant_id'): bool
    {
        return isset($resource[$field]) && (int) $resource[$field] === $tenantId;
    }

    public function assertTenantAccess(User $user, array $resource, string $field = 'tenant_id'): void
    {
        $tenantId = (int) ($user->tenant_id ?? 1);

        if (!$this->belongsToTenant($resource, $tenantId, $field)) {
            throw new \Core\Exceptions\HttpException('Acceso denegado a recurso de otro tenant.', 403);
        }
    }

    public function canManageMediaUser(User $user, int $mediaUserId): bool
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT tenant_id FROM media_users WHERE id = ? AND deleted_at IS NULL',
            [$mediaUserId]
        );

        return $row && (int) $row['tenant_id'] === (int) ($user->tenant_id ?? 1);
    }

    public function canManageServer(User $user, string $serverUuid): bool
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT tenant_id FROM servers WHERE uuid = ?',
            [$serverUuid]
        );

        return $row && (int) $row['tenant_id'] === (int) ($user->tenant_id ?? 1);
    }
}
