<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div class="min-w-0">
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Limpieza IPTV ↔ Plex</h4>
        <p class="text-muted small mb-0">Candidatos probables de IPTV mezclados en Plex. Soft-delete o detach con confirmación.</p>
    </div>
</div>

<div class="alert alert-warning small">
    <strong>Heurística (score ≥ 2):</strong>
    <ul class="mb-1 ps-3">
        <?php foreach ($heuristic as $line): ?>
        <li><?= e($line) ?></li>
        <?php endforeach; ?>
    </ul>
    Documentación: <code>docs/IPTV_CLEANUP.md</code>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/media-users/cleanup-iptv" class="d-flex flex-wrap gap-2 align-items-center">
            <label class="small text-muted mb-0">Servidor Plex:</label>
            <select name="server_id" class="form-select form-select-sm" style="min-width: 180px;" onchange="this.form.submit()">
                <option value="">Todos / sin servidor</option>
                <?php foreach ($servers as $server): ?>
                <?php if ($server->type !== 'plex') continue; ?>
                <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>>
                    <?= e($server->name) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<form method="POST" action="/media-users/cleanup-iptv" id="iptvCleanupForm">
    <?= csrf_field() ?>
    <?php if ($currentServerId): ?>
    <input type="hidden" name="server_id" value="<?= (int) $currentServerId ?>">
    <?php endif; ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="px-3 py-2 border-bottom bg-light small d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><strong><?= count($candidates) ?></strong> candidato(s)</span>
            <label class="mb-0 d-inline-flex align-items-center gap-1">
                <input type="checkbox" class="form-check-input m-0" id="iptvSelectAll">
                <span>Seleccionar todos</span>
            </label>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 2.5rem;"></th>
                        <th>Score</th>
                        <th>Usuario</th>
                        <th class="d-none d-md-table-cell">Email</th>
                        <th>Servidor</th>
                        <th class="d-none d-lg-table-cell">Motivos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($candidates)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No hay candidatos con la heurística actual</td></tr>
                    <?php else: ?>
                    <?php foreach ($candidates as $item): ?>
                    <?php $u = $item['user']; ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input iptv-select" name="uuids[]" value="<?= e($u->uuid) ?>">
                        </td>
                        <td><span class="badge bg-danger"><?= (int) $item['score'] ?></span></td>
                        <td>
                            <a href="/media-users/<?= e($u->uuid) ?>" class="fw-medium text-decoration-none"><?= e($u->display_name ?? $u->username) ?></a>
                            <div class="small text-muted d-md-none"><?= e($u->email ?? '-') ?></div>
                        </td>
                        <td class="small d-none d-md-table-cell"><?= e($u->email ?? '-') ?></td>
                        <td class="small">
                            <?php if (!empty($u->server_name)): ?>
                            <span class="badge bg-light text-dark border"><?= e($u->server_name) ?></span>
                            <?php else: ?>
                            <span class="text-muted">Sin servidor</span>
                            <?php endif; ?>
                        </td>
                        <td class="small d-none d-lg-table-cell">
                            <?= e(implode(' · ', $item['reasons'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($candidates)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Acción</label>
                    <select name="action" class="form-select form-select-sm" required>
                        <option value="detach">Detach (quitar servidor + suspender)</option>
                        <option value="soft_delete">Soft-delete (deleted_at)</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small">Escribe <code>LIMPIAR IPTV</code> para confirmar</label>
                    <input type="text" name="confirm" class="form-control form-control-sm" required autocomplete="off" placeholder="LIMPIAR IPTV">
                </div>
                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Aplicar limpieza a los seleccionados? No es hard-delete, pero sí irreversible desde la UI.')">
                        <i class="bi bi-funnel me-1"></i>Aplicar a selección
                    </button>
                </div>
            </div>
            <p class="small text-muted mt-2 mb-0">No borra filas de la BD ni cuentas remotas en Plex. Revisa score y motivos antes de confirmar.</p>
        </div>
    </div>
    <?php endif; ?>
</form>
<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
(function () {
    const all = document.getElementById('iptvSelectAll');
    if (!all) return;
    all.addEventListener('change', () => {
        document.querySelectorAll('.iptv-select').forEach((el) => { el.checked = all.checked; });
    });
})();
</script>
JS;
include base_path('resources/views/layouts/app.php');
?>
