<?php ob_start(); ?>
<h4 class="mb-4">Actualizaciones del sistema</h4>

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
                <?php if ($status['needs_update']): ?>
                <form method="POST" action="/updater/run">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary"><i class="bi bi-arrow-up-circle me-1"></i>Ejecutar actualización</button>
                </form>
                <?php else: ?>
                <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>Sistema actualizado</div>
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
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
