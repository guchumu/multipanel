<?php ob_start(); ?>
<h4 class="mb-4">Roles y permisos (RBAC)</h4>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Rol</th><th>Nivel</th><th>Usuarios</th><th>Sistema</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
            <tr>
                <td><strong><?= e($role['name']) ?></strong><br><small class="text-muted"><?= e($role['slug']) ?></small></td>
                <td><?= (int) $role['level'] ?></td>
                <td><?= (int) ($role['users_count'] ?? 0) ?></td>
                <td><?= !empty($role['is_system']) ? 'Sí' : 'No' ?></td>
                <td><a href="/roles/<?= (int) $role['id'] ?>/edit" class="btn btn-sm btn-outline-primary">Permisos</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
