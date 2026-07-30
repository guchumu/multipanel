<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Usuarios Media</h4>
    <a href="/media-users/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo usuario</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="btn-group btn-group-sm">
            <a href="/media-users" class="btn btn-outline-secondary <?= !$currentStatus ? 'active' : '' ?>">Todos</a>
            <a href="/media-users?status=active" class="btn btn-outline-success <?= $currentStatus === 'active' ? 'active' : '' ?>">Activos</a>
            <a href="/media-users?status=suspended" class="btn btn-outline-warning <?= $currentStatus === 'suspended' ? 'active' : '' ?>">Suspendidos</a>
            <a href="/media-users?status=pending" class="btn btn-outline-secondary <?= $currentStatus === 'pending' ? 'active' : '' ?>">Pendientes</a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th>Streams</th>
                    <th>Expira</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No hay usuarios</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u->display_name ?? $u->username) ?></td>
                    <td class="small"><?= e($u->email ?? '-') ?></td>
                    <td><span class="badge bg-secondary"><?= e($u->status) ?></span></td>
                    <td><?= (int) $u->max_streams ?></td>
                    <td class="small"><?= e($u->expires_at ?? 'Sin límite') ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <?php if ($u->status === 'active'): ?>
                            <button class="btn btn-outline-warning" onclick="suspendUser('<?= e($u->uuid) ?>')"><i class="bi bi-pause"></i></button>
                            <?php else: ?>
                            <button class="btn btn-outline-success" onclick="activateUser('<?= e($u->uuid) ?>')"><i class="bi bi-play"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
async function suspendUser(uuid) {
    await fetch(`/media-users/${uuid}/suspend`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    location.reload();
}
async function activateUser(uuid) {
    await fetch(`/media-users/${uuid}/activate`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    location.reload();
}
</script>
JS;
include base_path('resources/views/layouts/app.php');
