<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Logs de auditoría</h4>
    <a href="/logs/export" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Exportar CSV</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="action" class="form-control form-control-sm" placeholder="Filtrar por acción..." value="<?= e($currentAction ?? '') ?>">
            <button class="btn btn-sm btn-primary">Filtrar</button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Entidad</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No hay registros</td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="small text-nowrap"><?= e($log['created_at']) ?></td>
                    <td class="small"><?= e($log['username'] ?? 'Sistema') ?></td>
                    <td><code class="small"><?= e($log['action']) ?></code></td>
                    <td class="small"><?= e(($log['entity_type'] ?? '') . ' #' . ($log['entity_id'] ?? '')) ?></td>
                    <td class="small text-muted"><?= e($log['ip_address'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $perPage): ?>
    <div class="card-footer bg-white d-flex justify-content-between">
        <small class="text-muted"><?= (int) $total ?> registros</small>
        <nav>
            <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>" class="btn btn-sm btn-outline-secondary">Anterior</a><?php endif; ?>
            <?php if ($page * $perPage < $total): ?><a href="?page=<?= $page + 1 ?>" class="btn btn-sm btn-outline-secondary ms-1">Siguiente</a><?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
