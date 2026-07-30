<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="text-white mb-0">Backups</h4>
    <div>
        <form method="POST" action="/backups" class="d-inline"><?= csrf_field() ?><button class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Backup completo</button></form>
        <form method="POST" action="/backups/incremental" class="d-inline ms-1"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm">Incremental</button></form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Archivo</th>
                    <th>Tamaño</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backups)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No hay backups registrados</td></tr>
                <?php else: ?>
                <?php foreach ($backups as $b): ?>
                <tr>
                    <td><?= e($b['filename']) ?></td>
                    <td><?= number_format((int) $b['size_bytes'] / 1024 / 1024, 2) ?> MB</td>
                    <td><span class="badge bg-secondary"><?= e($b['type']) ?></span></td>
                    <td><span class="badge bg-<?= $b['status'] === 'completed' ? 'success' : 'warning' ?>"><?= e($b['status']) ?></span></td>
                    <td class="small text-muted"><?= e($b['created_at']) ?></td>
                    <td class="text-end">
                        <a href="/backups/<?= (int) $b['id'] ?>/download" class="btn btn-sm btn-outline-primary">Descargar</a>
                        <form method="POST" action="/backups/<?= (int) $b['id'] ?>" class="d-inline" onsubmit="return confirm('¿Eliminar backup?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_method" value="DELETE">
                            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (config('backup.remote.enabled')): ?>
<div class="alert alert-info mt-3 small">
    <i class="bi bi-cloud-check me-1"></i>Copia remota activa (<?= e(config('backup.remote.driver', 'webhook')) ?>)
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include base_path('resources/views/layouts/app.php');
