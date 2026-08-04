<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Servidores</h4>
    <div class="d-flex gap-2">
        <form method="POST" action="/servers/sync-all" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Sincronizar todos</button>
        </form>
        <a href="/servers/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo servidor</a>
    </div>
</div>

<p class="text-muted small mb-3">
    <i class="bi bi-star-fill text-warning me-1"></i>
    Marca con la estrella el servidor por defecto para altas automáticas — uno para <strong>Plex</strong> y otro para <strong>Jellyfin</strong>.
</p>

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
                    <th class="d-none d-lg-table-cell">Carga</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($servers)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No hay servidores. <a href="/servers/create">Añadir uno</a></td></tr>
                <?php else: ?>
                <?php foreach ($servers as $server): ?>
                <tr>
                    <td>
                        <?php $isDefault = $server->isDefault(); ?>
                        <button type="button"
                                class="btn btn-link btn-sm p-0 me-1 align-middle btn-default-star <?= $isDefault ? 'is-default' : '' ?>"
                                data-uuid="<?= e($server->uuid) ?>"
                                data-type="<?= e($server->type) ?>"
                                title="<?= $isDefault ? 'Servidor ' . strtoupper($server->type) . ' por defecto' : 'Marcar como predeterminado ' . strtoupper($server->type) ?>">
                            <i class="bi bi-star<?= $isDefault ? '-fill text-warning' : ' text-muted' ?>"></i>
                        </button>
                        <a href="/servers/<?= e($server->uuid) ?>" class="fw-medium align-middle"><?= e($server->name) ?></a>
                        <div class="small text-muted d-md-none"><?= e($server->displayHost()) ?>:<?= (int) $server->port ?></div>
                    </td>
                    <td><span class="badge bg-<?= $server->type === 'plex' ? 'warning' : 'info' ?>"><?= e(strtoupper($server->type)) ?></span></td>
                    <td class="small text-muted d-none d-md-table-cell"><?= e($server->displayHost()) ?>:<?= (int) $server->port ?></td>
                    <td>
                        <?php $badge = match($server->status) { 'online'=>'success', 'offline'=>'danger', 'error'=>'warning', default=>'secondary' }; ?>
                        <span class="badge bg-<?= $badge ?>"><?= e($server->status) ?></span>
                        <?php if ($server->status !== 'online' && $server->last_error): ?>
                        <div class="small text-danger mt-1" title="<?= e($server->last_error) ?>"><?= e(mb_strimwidth((string) $server->last_error, 0, 60, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small d-none d-lg-table-cell"><?= e($server->version ?? '-') ?></td>
                    <td class="d-none d-sm-table-cell"><?= (int) $server->active_sessions ?></td>
                    <td class="d-none d-lg-table-cell small">
                        <?php $l = $load[(int) $server->id] ?? null; ?>
                        <?php if ($l): ?>
                        <span class="badge bg-primary" title="Reproducciones activas"><?= (int) $l['sessions'] ?> ses.</span>
                        <?php if ($l['transcode'] > 0): ?>
                        <span class="badge bg-warning text-dark" title="Transcodificando"><?= (int) $l['transcode'] ?> trans.</span>
                        <?php endif; ?>
                        <?php if ($l['direct_play'] > 0): ?>
                        <span class="badge bg-success" title="Direct play"><?= (int) $l['direct_play'] ?> direct</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="/servers/<?= e($server->uuid) ?>/edit" class="btn btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                            <button class="btn btn-outline-primary btn-sync" data-uuid="<?= e($server->uuid) ?>" title="Sincronizar"><i class="bi bi-arrow-repeat"></i></button>
                            <button class="btn btn-outline-success btn-test" data-uuid="<?= e($server->uuid) ?>" title="Test conexión"><i class="bi bi-plug"></i></button>
                            <a href="/servers/<?= e($server->uuid) ?>" class="btn btn-outline-warning" title="Ver debug"><i class="bi bi-bug"></i></a>
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
<script src="/assets/js/server-actions.js"></script>
JS;
include base_path('resources/views/layouts/app.php');
