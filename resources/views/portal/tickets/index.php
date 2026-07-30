<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="text-white mb-0">Mis tickets</h4>
    <a href="/portal/tickets/create" class="btn btn-light btn-sm">Nuevo ticket</a>
</div>
<div class="card portal-card">
    <div class="list-group list-group-flush">
        <?php if (empty($tickets)): ?>
        <div class="list-group-item text-center text-muted py-4">No tienes tickets abiertos</div>
        <?php else: ?>
        <?php foreach ($tickets as $t): ?>
        <a href="/portal/tickets/<?= e($t['uuid']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
            <span><?= e($t['subject']) ?></span>
            <span class="badge bg-secondary"><?= e($t['status']) ?></span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
