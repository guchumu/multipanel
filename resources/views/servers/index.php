<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Servidores</h4>
    <a href="/servers/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo servidor</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>URL</th>
                    <th>Estado</th>
                    <th>Versión</th>
                    <th>Sesiones</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($servers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No hay servidores. <a href="/servers/create">Añadir uno</a></td></tr>
                <?php else: ?>
                <?php foreach ($servers as $server): ?>
                <tr>
                    <td><a href="/servers/<?= e($server->uuid) ?>"><?= e($server->name) ?></a></td>
                    <td><span class="badge bg-<?= $server->type === 'plex' ? 'warning' : 'info' ?>"><?= e(strtoupper($server->type)) ?></span></td>
                    <td class="small text-muted"><?= e($server->fullUrl()) ?></td>
                    <td>
                        <?php $badge = match($server->status) { 'online'=>'success', 'offline'=>'danger', default=>'secondary' }; ?>
                        <span class="badge bg-<?= $badge ?>"><?= e($server->status) ?></span>
                    </td>
                    <td class="small"><?= e($server->version ?? '-') ?></td>
                    <td><?= (int) $server->active_sessions ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary btn-sync" data-uuid="<?= e($server->uuid) ?>" title="Sincronizar"><i class="bi bi-arrow-repeat"></i></button>
                            <button class="btn btn-outline-success btn-test" data-uuid="<?= e($server->uuid) ?>" title="Test conexión"><i class="bi bi-plug"></i></button>
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
