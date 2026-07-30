<?php ob_start(); ?>
<h4 class="mb-4">Empresas / Tenants</h4>
<div class="card border-0 shadow-sm">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Nombre</th><th>Slug</th><th>Plan</th><th>Estado</th><th>Acción</th></tr></thead>
        <tbody>
            <?php foreach ($tenants as $t): ?>
            <tr class="<?= (int)$t['id'] === (int)$currentTenantId ? 'table-primary' : '' ?>">
                <td><?= e($t['name']) ?></td>
                <td><code><?= e($t['slug']) ?></code></td>
                <td><?= e($t['plan']) ?></td>
                <td><span class="badge bg-<?= $t['status']==='active'?'success':'secondary' ?>"><?= e($t['status']) ?></span></td>
                <td>
                    <?php if ((int)$t['id'] !== (int)$currentTenantId): ?>
                    <form method="POST" action="/tenants/<?= (int)$t['id'] ?>/switch" class="d-inline"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-primary">Cambiar</button>
                    </form>
                    <?php else: ?>
                    <span class="badge bg-primary">Activo</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
