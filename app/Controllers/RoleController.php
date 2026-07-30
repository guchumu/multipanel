<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PermissionService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * RBAC roles and permissions management.
 */
class RoleController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private PermissionService $permissions = new PermissionService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'roles.manage');

        $tenantId = (int) ($user->tenant_id ?? 1);

        return $this->view('roles.index', [
            'title' => 'Roles y permisos',
            'roles' => $this->permissions->rolesForTenant($tenantId),
            'permissions' => $this->permissions->allPermissions(),
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'roles.manage');

        $role = Database::getInstance()->fetchOne('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$role) {
            Session::getInstance()->flash('error', 'Rol no encontrado.');
            return $this->redirect('/roles');
        }

        $assigned = Database::getInstance()->fetchAll(
            'SELECT permission_id FROM role_permissions WHERE role_id = ?',
            [$id]
        );

        return $this->view('roles.edit', [
            'title' => 'Editar rol: ' . $role['name'],
            'role' => $role,
            'permissions' => $this->permissions->allPermissions(),
            'assigned' => array_column($assigned, 'permission_id'),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'roles.manage');

        $role = Database::getInstance()->fetchOne('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$role || !empty($role['is_system']) && (int) $id <= 2) {
            Session::getInstance()->flash('error', 'No se puede modificar este rol del sistema.');
            return $this->redirect('/roles');
        }

        $permissionIds = $request->input('permissions', []);
        if (!is_array($permissionIds)) {
            $permissionIds = [];
        }

        $this->permissions->syncRolePermissions($id, $permissionIds);

        Session::getInstance()->flash('success', 'Permisos actualizados.');
        return $this->redirect('/roles/' . $id . '/edit');
    }
}
