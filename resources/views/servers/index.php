<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Servidores</h4>
    <a href="/servers/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo servidor</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th class="d-none d-md-table-cell">URL</th>
                    <th>Estado</th>
                    <th class="d-none d-lg-table-cell">Versión</th>
                    <th class="d-none d-sm-table-cell">Sesiones</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($servers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No hay servidores. <a href="/servers/create">Añadir uno</a></td></tr>
                <?php else: ?>
                <?php foreach ($servers as $server): ?>
                <tr>
                    <td>
                        <a href="/servers/<?= e($server->uuid) ?>" class="fw-medium"><?= e($server->name) ?></a>
                        <div class="small text-muted d-md-none"><?= e($server->fullUrl()) ?></div>
                    </td>
                    <td><span class="badge bg-<?= $server->type === 'plex' ? 'warning' : 'info' ?>"><?= e(strtoupper($server->type)) ?></span></td>
                    <td class="small text-muted d-none d-md-table-cell"><?= e($server->fullUrl()) ?></td>
                    <td>
                        <?php $badge = match($server->status) { 'online'=>'success', 'offline'=>'danger', default=>'secondary' }; ?>
                        <span class="badge bg-<?= $badge ?>"><?= e($server->status) ?></span>
                    </td>
                    <td class="small d-none d-lg-table-cell"><?= e($server->version ?? '-') ?></td>
                    <td class="d-none d-sm-table-cell"><?= (int) $server->active_sessions ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="/servers/<?= e($server->uuid) ?>/edit" class="btn btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                            <button class="btn btn-outline-primary btn-sync" data-uuid="<?= e($server->uuid) ?>" title="Sincronizar"><i class="bi bi-arrow-repeat"></i></button>
                            <button class="btn btn-outline-success btn-test" data-uuid="<?= e($server->uuid) ?>" title="Test conexión"><i class="bi bi-plug"></i></button>
                            <form method="POST" action="/servers/<?= e($server->uuid) ?>" class="d-inline" onsubmit="return confirm('¿Eliminar <?= e(addslashes($server->name)) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="btn btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </form>
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
document.querySelectorAll('.btn-sync').forEach(btn => {
    btn.addEventListener('click', async function() {
        const uuid = this.dataset.uuid;
        this.disabled = true;
        const res = await fetch(`/servers/${uuid}/sync`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
        const data = await res.json();
        alert(data.message);
        location.reload();
    });
});
document.querySelectorAll('.btn-test').forEach(btn => {
    btn.addEventListener('click', async function() {
        const uuid = this.dataset.uuid;
        const res = await fetch(`/servers/${uuid}/test`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
        const data = await res.json();
        alert(data.message);
    });
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
