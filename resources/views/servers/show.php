<?php ob_start(); ?>
<div class="mb-4">
    <a href="/servers" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <div class="d-flex justify-content-between align-items-center mt-2">
        <h4 class="mb-0"><?= e($server->name) ?></h4>
        <span class="badge bg-<?= $server->status === 'online' ? 'success' : 'danger' ?> fs-6"><?= e($server->status) ?></span>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Información</h6>
                <dl class="mb-0">
                    <dt class="text-muted small">Tipo</dt><dd><?= e(strtoupper($server->type)) ?></dd>
                    <dt class="text-muted small">URL</dt><dd class="small"><?= e($server->fullUrl()) ?></dd>
                    <dt class="text-muted small">Versión</dt><dd><?= e($server->version ?? 'Desconocida') ?></dd>
                    <dt class="text-muted small">Machine ID</dt><dd class="small text-break"><?= e($server->machine_id ?? '-') ?></dd>
                    <dt class="text-muted small">Última sync</dt><dd><?= e($server->last_sync_at ?? 'Nunca') ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-3"><h3><?= (int) $server->active_sessions ?></h3><small class="text-muted">Sesiones</small></div>
                    <div class="col-3"><h3><?= (int) $server->total_libraries ?></h3><small class="text-muted">Bibliotecas</small></div>
                    <div class="col-3"><h3><?= (int) $server->total_users ?></h3><small class="text-muted">Usuarios</small></div>
                    <div class="col-3"><h3><?= e($server->health_score ?? 100) ?>%</h3><small class="text-muted">Salud</small></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
