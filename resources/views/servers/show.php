<?php ob_start(); ?>
<div class="mb-4">
    <a href="/servers" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
        <h4 class="mb-0"><?= e($server->name) ?></h4>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-<?= $server->status === 'online' ? 'success' : 'danger' ?> fs-6"><?= e($server->status) ?></span>
            <a href="/servers/<?= e($server->uuid) ?>/edit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Editar
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary btn-sync" data-uuid="<?= e($server->uuid) ?>" title="Reconsulta la lista real de usuarios y marca quién no está en la biblioteca">
                <i class="bi bi-arrow-repeat me-1"></i>Forzar sincronización
            </button>
            <button type="button" class="btn btn-sm btn-outline-info btn-scan-all" data-uuid="<?= e($server->uuid) ?>" title="Pide a <?= e(strtoupper($server->type)) ?> que escanee todas las bibliotecas en disco">
                <i class="bi bi-disc me-1"></i>Escanear todas
            </button>
            <button type="button" class="btn btn-sm btn-outline-success btn-test" data-uuid="<?= e($server->uuid) ?>">
                <i class="bi bi-plug me-1"></i>Test
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning btn-debug" data-uuid="<?= e($server->uuid) ?>">
                <i class="bi bi-bug me-1"></i>Debug
            </button>
        </div>
    </div>
</div>

<?php if ($server->status !== 'online' && $server->last_error): ?>
<div class="alert alert-warning">
    <strong>Servidor offline.</strong> <?= e($server->last_error) ?>
    <div class="small mt-1">Usa <strong>Forzar sincronización</strong> o <strong>Debug</strong> para reintentar manualmente.</div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Información</h6>
                <dl class="mb-0">
                    <dt class="text-muted small">Tipo</dt><dd><?= e(strtoupper($server->type)) ?></dd>
                    <dt class="text-muted small">URL</dt><dd class="small"><code><?= e($server->fullUrl()) ?></code></dd>
                    <dt class="text-muted small">Versión</dt><dd><?= e($server->version ?? 'Desconocida') ?></dd>
                    <dt class="text-muted small">Machine ID</dt><dd class="small text-break"><?= e($server->machine_id ?? '-') ?></dd>
                    <dt class="text-muted small">Última sync</dt><dd><?= e($server->last_sync_at ?? 'Nunca') ?></dd>
                    <dt class="text-muted small">Última comprobación</dt><dd><?= e($server->last_check_at ?? '-') ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row text-center g-3">
                    <div class="col-6 col-md-3"><h3 class="mb-0"><?= (int) $server->active_sessions ?></h3><small class="text-muted">Streams activos</small></div>
                    <div class="col-6 col-md-3"><h3 class="mb-0"><?= max((int) $server->total_libraries, (int) ($panelLibraries ?? 0)) ?></h3><small class="text-muted">Bibliotecas</small></div>
                    <div class="col-6 col-md-3"><h3 class="mb-0"><?= max((int) $server->total_users, (int) ($panelUsers ?? 0)) ?></h3><small class="text-muted">Usuarios panel</small></div>
                    <div class="col-6 col-md-3"><h3 class="mb-0"><?= e($server->health_score ?? 100) ?>%</h3><small class="text-muted">Salud</small></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h6 class="mb-0">Bibliotecas</h6>
                <p class="text-muted small mb-0">
                    Escanea en <?= e(strtoupper($server->type)) ?> (disco/metadatos). No modifica usuarios ni permisos.
                    «Forzar sincronización» solo actualiza la copia en el panel.
                </p>
            </div>
            <button type="button" class="btn btn-sm btn-primary btn-scan-all" data-uuid="<?= e($server->uuid) ?>">
                <i class="bi bi-disc me-1"></i>Escanear todas
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th class="d-none d-md-table-cell">ID externo</th>
                        <th class="text-end">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($libraries)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            No hay bibliotecas sincronizadas en el panel.
                            Usa <strong>Forzar sincronización</strong> para importarlas.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($libraries as $library): ?>
                    <tr>
                        <td class="fw-medium"><?= e($library['name'] ?? '') ?></td>
                        <td><span class="badge bg-secondary"><?= e($library['type'] ?? '-') ?></span></td>
                        <td class="small text-muted d-none d-md-table-cell"><code><?= e($library['external_id'] ?? '') ?></code></td>
                        <td class="text-end">
                            <button type="button"
                                    class="btn btn-sm btn-outline-info btn-scan-library"
                                    data-uuid="<?= e($server->uuid) ?>"
                                    data-external-id="<?= e($library['external_id'] ?? '') ?>"
                                    title="Escanear esta biblioteca en <?= e(strtoupper($server->type)) ?>">
                                <i class="bi bi-disc me-1"></i>Escanear
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="debug-panel">
<?php include base_path('resources/views/servers/_connection_debug.php'); ?>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script src="/assets/js/server-actions.js"></script>
JS;
include base_path('resources/views/layouts/app.php');
