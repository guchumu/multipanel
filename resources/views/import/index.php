<?php ob_start(); ?>
<h4 class="mb-4">Importar / Exportar usuarios</h4>

<?php use Core\Session; if ($errs = Session::getInstance()->getFlash('import_errors')): ?>
<div class="alert alert-danger"><pre class="mb-0 small"><?= e($errs) ?></pre></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm border-primary mb-3">
            <div class="card-body">
                <h6><i class="bi bi-database-down me-1"></i>Migrar desde plex_manager</h6>
                <p class="text-muted small mb-2">Sube tu exportación <code>plex_manager.sql</code> (phpMyAdmin). Importa servidores, usuarios, fechas, Telegram y clientes CRM.</p>
                <div class="alert alert-warning py-2 small">El SQL puede contener tokens/contraseñas en texto claro. No lo subas a repositorios públicos. Tras migrar, rota credenciales si el archivo estuvo expuesto.</div>
                <form method="POST" action="/import" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="plex_manager">
                    <div class="mb-3"><input type="file" name="file" class="form-control" accept=".sql,.txt" required></div>
                    <button class="btn btn-primary">Importar plex_manager.sql</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Importar CSV / JSON</h6>
                <p class="text-muted small">Columnas CSV: username, email, expires_at, status, notes...</p>
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
                <?php use Core\Session; if ($errs = Session::getInstance()->getFlash('import_errors')): ?>
                <pre class="mt-3 small text-danger bg-light p-2 rounded d-none"><?= e($errs) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Qué se importa del SQL</h6>
                <ul class="small text-muted mb-3">
                    <li><strong>servers</strong> → Servidores Plex (URL, token, machine_id)</li>
                    <li><strong>users</strong> → Usuarios media + clientes CRM</li>
                    <li><strong>end_date</strong> → Fecha expiración</li>
                    <li><strong>start_date</strong> → Fecha contratación (suscripción)</li>
                    <li><strong>telegram_id / telegram_chat_id</strong> → metadata del cliente</li>
                    <li><strong>plex_username / plex_user_id</strong> → usuario e ID externo</li>
                </ul>
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
