<?php ob_start(); ?>
<h4 class="mb-4">Plugins</h4>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Instalados</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($installed)): ?>
                <li class="list-group-item text-muted text-center">Ningún plugin instalado</li>
                <?php else: ?>
                <?php foreach ($installed as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= e($p['name']) ?></strong> <small class="text-muted">v<?= e($p['version']) ?></small>
                        <br><small><?= e($p['description'] ?? '') ?></small>
                    </div>
                    <?php if ($p['is_active']): ?>
                    <form method="POST" action="/plugins/<?= e($p['slug']) ?>/deactivate"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-warning">Desactivar</button>
                    </form>
                    <?php else: ?>
                    <span class="badge bg-secondary">Inactivo</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Disponibles</h6></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($discovered as $p): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <div><strong><?= e($p['name']) ?></strong><br><small class="text-muted"><?= e($p['description'] ?? '') ?></small></div>
                    <?php if (!in_array($p['slug'], $installedSlugs, true)): ?>
                    <form method="POST" action="/plugins/<?= e($p['slug']) ?>/install"><?= csrf_field() ?>
                        <button class="btn btn-sm btn-primary">Instalar</button>
                    </form>
                    <?php else: ?>
                    <span class="badge bg-success">Instalado</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
