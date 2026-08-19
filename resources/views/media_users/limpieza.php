<?php
ob_start();
$servicioLabels = $servicioLabels ?? [1 => 'Server10', 5 => 'NucBox'];
$servicioMap = $servicioMap ?? [];
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div class="min-w-0">
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Limpieza / reinicio usuarios media</h4>
        <p class="text-muted small mb-0">Flujo recomendado para dejar solo usuarios reales de biblioteca con fechas del importador (servicio 1 y 5).</p>
    </div>
</div>

<div class="alert alert-info small">
    <strong>Orden:</strong>
    <ol class="mb-0 ps-3">
        <li><strong>Borrar todos</strong> los usuarios media del panel (soft-delete; no toca Plex/Jellyfin).</li>
        <li><strong>Forzar sincronización</strong> de cada servidor → solo quedan los que están de verdad en la biblioteca.</li>
        <li><strong>Importar fechas/datos</strong> con filtro <code>servicio IN (1, 5)</code>:
            <?php foreach ($servicioLabels as $code => $label): ?>
                <span class="badge bg-light text-dark border"><?= (int) $code ?> = <?= e((string) $label) ?></span>
            <?php endforeach; ?>
            El resto de códigos de servicio se ignora.
        </li>
    </ol>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-danger shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Zona peligrosa</h6>
                <p class="small text-muted">Hay <strong><?= (int) $activeCount ?></strong> usuarios media activos en el panel.</p>
                <p class="small">
                    Esto hace <strong>soft-delete</strong> (<code>deleted_at</code>) de <em>todos</em> los usuarios media del tenant.
                    <strong>No elimina cuentas en Plex ni Jellyfin</strong> — solo limpia la copia del panel.
                </p>
                <form method="POST" action="/media-users/limpieza/wipe" onsubmit="return confirm('¿Seguro? Se ocultarán TODOS los usuarios media del panel. Plex/Jellyfin no se tocan.');">
                    <?= csrf_field() ?>
                    <label class="form-label small">Escribe <code><?= e($confirmPhrase) ?></code> para confirmar</label>
                    <input type="text" name="confirm" class="form-control form-control-sm mb-3" required autocomplete="off" placeholder="<?= e($confirmPhrase) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i>Borrar todos los usuarios media
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6><i class="bi bi-arrow-repeat me-1"></i>Paso 2 — Forzar sincronización</h6>
                <p class="small text-muted">Tras el wipe, sincroniza para recrear solo los usuarios presentes en cada servidor.</p>
                <form method="POST" action="/media-users/sync-membership" class="mb-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-repeat me-1"></i>Forzar sync de todos los servidores
                    </button>
                </form>
                <?php if (!empty($servers)): ?>
                <div class="list-group list-group-flush small">
                    <?php foreach ($servers as $server): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>
                            <span class="badge bg-light text-dark border me-1"><?= e($server->type) ?></span>
                            <?= e($server->name) ?>
                        </span>
                        <form method="POST" action="/media-users/sync-membership" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="server_id" value="<?= (int) $server->id ?>">
                            <button class="btn btn-outline-primary btn-sm" title="Forzar sincronización">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="small text-muted mb-0">No hay servidores configurados.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
        <h6><i class="bi bi-calendar-event me-1"></i>Paso 3 — Importar fechas/datos (solo servicio 1 y 5)</h6>
        <p class="small text-muted mb-2">
            Sube <code>plex_manager.sql</code> en modo <strong>solo metadata</strong>: actualiza
            <code>expires_at</code>, Telegram y email sobre usuarios ya sincronizados, emparejando por email/username
            en el servidor correcto (1→Server10, 5→NucBox).
        </p>
        <?php if (!empty($servicioMap)): ?>
        <p class="small text-muted mb-3">
            Match por nombre de servidor (configurable con <code>IMPORT_SERVICIO_1_SERVERS</code> / <code>IMPORT_SERVICIO_5_SERVERS</code>):
            <?php foreach ($servicioMap as $code => $needles): ?>
                <code><?= (int) $code ?></code> → <?= e(implode(', ', (array) $needles)) ?>;
            <?php endforeach; ?>
        </p>
        <?php endif; ?>
        <form method="POST" action="/import" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="plex_manager">
            <input type="hidden" name="mode" value="overlay">
            <div class="col-md-5">
                <label class="form-label small">Archivo SQL</label>
                <input type="file" name="file" class="form-control form-control-sm" accept=".sql,.txt">
            </div>
            <div class="col-md-4">
                <label class="form-label small">O en servidor (<code>storage/imports/</code>)</label>
                <input type="text" name="server_path" class="form-control form-control-sm" placeholder="plex_manager.sql">
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-upload me-1"></i>Importar fechas/datos (servicio 1 y 5)
                </button>
                <a href="/import" class="btn btn-link btn-sm">Ir a Importar / Exportar</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4 border-success">
    <div class="card-body">
        <h6><i class="bi bi-cloud-download me-1"></i>Paso 3b — Sincronizar desde series.clientes (BD remota)</h6>
        <p class="small text-muted mb-2">
            Alternativa al SQL de plex_manager: lee en vivo <code>series.clientes</code> (misma conexión que Peticiones).
            Servicio <strong>1 y 5</strong> aportan caducidad/email/notas; el resto solo puede rellenar Telegram.
        </p>
        <form method="POST" action="/import/series-clientes" class="d-flex flex-wrap gap-3 align-items-center">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="/media-users/limpieza">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="seriesOverwriteLimpieza">
                <label class="form-check-label small" for="seriesOverwriteLimpieza">Sobrescribir campos ya rellenados</label>
            </div>
            <button class="btn btn-success btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sincronizar vencimientos remotas
            </button>
            <a href="/import" class="btn btn-link btn-sm">Ir a Importar / Exportar</a>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
include base_path('resources/views/layouts/app.php');
?>
