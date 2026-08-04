<?php ob_start(); ?>
<h4 class="mb-4">Actualizaciones del sistema</h4>

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Este panel <strong>importa y aplica</strong> las migraciones SQL de <code>database/migrations/</code>
    (tablas/columnas nuevas como <code>media_user_messages</code>, historial de pagos, servidor por defecto, etc.).
    Con <code>AUTO_MIGRATE=true</code> en <code>.env</code> también se aplican al cargar el panel.
    Desde CLI: <code>php cron/run.php migrate</code>.
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Estado actual</h6>
                <dl>
                    <dt class="text-muted small">Versión</dt><dd><?= e($status['current_version']) ?></dd>
                    <dt class="text-muted small">PHP</dt><dd><?= e($status['php_version']) ?></dd>
                    <dt class="text-muted small">Migraciones pendientes</dt><dd><span class="badge bg-<?= $status['needs_update'] ? 'warning' : 'success' ?>"><?= (int)$status['pending_migrations'] ?></span></dd>
                </dl>
                <form method="POST" action="/updater/run">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary">
                        <i class="bi bi-arrow-up-circle me-1"></i>
                        <?= $status['needs_update'] ? 'Importar actualizaciones' : 'Comprobar / reparar migraciones' ?>
                    </button>
                </form>
                <?php if (!$status['needs_update']): ?>
                <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle me-2"></i>Sistema actualizado (sin pendientes)</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Migraciones pendientes</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($pending)): ?>
                <li class="list-group-item text-muted text-center">Ninguna</li>
                <?php else: ?>
                <?php foreach ($pending as $m): ?>
                <li class="list-group-item"><code><?= e($m) ?></code></li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body small text-muted">
                <h6 class="text-dark">Cómo funciona</h6>
                <ol class="mb-0 ps-3">
                    <li>Haz <code>git pull</code> para recibir los archivos <code>database/migrations/*.sql</code>.</li>
                    <li>Entra en <strong>Sistema → Actualizaciones</strong>.</li>
                    <li>Pulsa <strong>Importar actualizaciones</strong> (o deja que <code>AUTO_MIGRATE</code> las aplique al cargar).</li>
                </ol>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
