<?php
use Core\Session;

ob_start();
$importErrors = Session::getInstance()->getFlash('import_errors');
$serverFiles = $serverFiles ?? [];
$phpUploadMax = $phpUploadMax ?? (string) ini_get('upload_max_filesize');
$phpPostMax = $phpPostMax ?? (string) ini_get('post_max_size');
?>
<h4 class="mb-4">Importar / Exportar usuarios</h4>

<?php if ($importErrors): ?>
<div class="alert alert-danger"><pre class="mb-0 small"><?= e($importErrors) ?></pre></div>
<?php endif; ?>

<div class="alert alert-secondary small">
    <strong>Filtro servicio:</strong> del SQL/CSV solo se aplican filas con
    <code>servicio</code> / <code>service</code> <strong>1</strong> (Server10) o <strong>5</strong> (NucBox).
    El resto de códigos de servicio se ignora. Si la fila no trae esa columna, se infiere por
    <code>payments_history.service</code> o por el nombre del servidor legacy (Server10/Nucbox).
    Flujo limpio: <a href="/media-users/limpieza">Usuarios → Limpieza / reinicio</a>
    (borrar todos → sync → importar fechas).
</div>

<div class="alert alert-info small">
    <strong>Límites PHP de este request:</strong>
    <code>upload_max_filesize=<?= e($phpUploadMax) ?></code>,
    <code>post_max_size=<?= e($phpPostMax) ?></code>.
    Si el navegador falla al subir (~2 MB+), usa <strong>FTP → <code>storage/imports/</code></strong>
    y el campo «Archivo en servidor» (solo el nombre, ej. <code>plex_manager.sql</code>).
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm border-primary mb-3">
            <div class="card-body">
                <h6><i class="bi bi-database-down me-1"></i>Migrar desde plex_manager</h6>
                <p class="text-muted small mb-2">Sube tu exportación <code>plex_manager.sql</code> (phpMyAdmin). Importa servidores, usuarios (solo servicio 1/5), fechas, Telegram y clientes CRM.</p>
                <div class="alert alert-warning py-2 small">El SQL puede contener tokens/contraseñas en texto claro. No lo subas a repositorios públicos. Tras migrar, rota credenciales si el archivo estuvo expuesto.</div>
                <form method="POST" action="/import" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="plex_manager">
                    <div class="mb-3">
                        <label class="form-label small">Modo</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="full">Completo (servidores + usuarios filtrados)</option>
                            <option value="overlay" selected>Solo fechas/datos sobre usuarios ya sincronizados (recomendado tras wipe+sync)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Archivo SQL (subida HTTP)</label>
                        <input type="file" name="file" class="form-control" accept=".sql,.txt">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">O archivo en servidor (FTP → <code>storage/imports/</code>)</label>
                        <input type="text" name="server_path" class="form-control form-control-sm" list="importServerFiles" placeholder="plex_manager.sql" autocomplete="off">
                        <?php if ($serverFiles !== []): ?>
                        <datalist id="importServerFiles">
                            <?php foreach ($serverFiles as $sf): ?>
                            <option value="<?= e($sf['name']) ?>"><?= e($sf['name']) ?> (<?= e((string) round($sf['bytes'] / 1048576, 2)) ?> MB)</option>
                            <?php endforeach; ?>
                        </datalist>
                        <p class="small text-muted mb-0 mt-1">Detectados: <?= e(implode(', ', array_column($serverFiles, 'name'))) ?></p>
                        <?php else: ?>
                        <p class="small text-muted mb-0 mt-1">Ningún <code>.sql</code> en <code>storage/imports/</code> todavía.</p>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-primary">Importar plex_manager.sql</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Importar CSV / JSON</h6>
                <p class="text-muted small">Columnas CSV: username, email, telegram_chat_id, expires_at, status, notes, servicio (opcional: solo 1 o 5)…</p>
                <form method="POST" action="/import" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <select name="type" class="form-select form-select-sm">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="mb-3"><input type="file" name="file" class="form-control" accept=".csv,.json,.txt" required></div>
                    <button class="btn btn-outline-primary">Importar</button>
                    <a href="/import/template" class="btn btn-outline-secondary ms-2">Plantilla CSV</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Qué se importa del SQL</h6>
                <ul class="small text-muted mb-3">
                    <li><strong>servers</strong> → Servidores Plex (URL, token, machine_id) — solo modo completo</li>
                    <li><strong>users</strong> → Usuarios media + clientes CRM (filtrados por servicio 1/5)</li>
                    <li><strong>servicio / service / payments_history</strong> → 1=Server10, 5=NucBox</li>
                    <li><strong>end_date / expires_at / expiration</strong> → Fecha expiración (<code>media_users.expires_at</code>)</li>
                    <li><strong>start_date</strong> → Fecha contratación (suscripción)</li>
                    <li><strong>telegram_chat_id / telegram_id / idcliente</strong> → Chat ID Telegram (<code>media_users.telegram_chat_id</code>); si falta, se intenta desde <code>payments_history</code></li>
                    <li><strong>private_notes / notes / admin_notes</strong> → Notas (<code>media_users.notes</code>)</li>
                    <li><strong>plex_username / plex_user_id</strong> → usuario e ID externo</li>
                </ul>
                <h6>SQL grande por FTP</h6>
                <ol class="small text-muted mb-3">
                    <li>Sube <code>plex_manager.sql</code> por FTP/SFTP a <code>storage/imports/</code> del proyecto.</li>
                    <li>En este formulario, deja el file vacío y escribe <code>plex_manager.sql</code> en «Archivo en servidor».</li>
                    <li>Elige modo overlay o completo e importa.</li>
                </ol>
                <h6>Exportar</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="/logs/export" class="btn btn-outline-secondary btn-sm w-100">Logs auditoría (CSV)</a></li>
                    <li class="mb-2"><a href="/stats/export" class="btn btn-outline-secondary btn-sm w-100">Estadísticas streaming (CSV)</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
