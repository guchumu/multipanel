<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Core\Database;

/**
 * RBAC permission checker.
 */
final class PermissionService
{
    /** @var array<int, list<string>> */
    private static array $cache = [];

    public function can(User $user, string $permission): bool
    {
        if ((int) $user->role_id <= 2) {
            return true;
        }

        $permissions = $this->getRolePermissions((int) $user->role_id);
        return in_array($permission, $permissions, true) || in_array('*', $permissions, true);
    }

    public function authorize(User $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new \Core\Exceptions\HttpException('No tienes permiso para esta acción.', 403);
        }
    }

    /** @return list<string> */
    public function getRolePermissions(int $roleId): array
    {
        if (isset(self::$cache[$roleId])) {
            return self::$cache[$roleId];
        }

        $rows = Database::getInstance()->fetchAll(
            'SELECT p.slug FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = ?',
            [$roleId]
        );

        self::$cache[$roleId] = array_column($rows, 'slug');
        return self::$cache[$roleId];
    }

    /** @return list<array<string, mixed>> */
    public function allPermissions(): array
    {
        return Database::getInstance()->fetchAll('SELECT * FROM permissions ORDER BY `group`, name');
    }

    /** @return list<array<string, mixed>> */
    public function rolesForTenant(int $tenantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) as users_count
             FROM roles r WHERE r.tenant_id = ? OR r.tenant_id IS NULL ORDER BY r.level DESC',
            [$tenantId]
        );
    }

    public function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        $db = Database::getInstance();
        $db->query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

        foreach ($permissionIds as $pid) {
            $db->insert('role_permissions', [
                'role_id' => $roleId,
                'permission_id' => (int) $pid,
            ]);
        }

        unset(self::$cache[$roleId]);
    }
}
