<?php ob_start(); ?>
<h4 class="text-white mb-4"><?= e($ticket['subject']) ?></h4>
<div class="card portal-card mb-3">
    <div class="card-body">
        <span class="badge bg-secondary"><?= e($ticket['status']) ?></span>
        <span class="badge bg-info ms-1"><?= e($ticket['priority']) ?></span>
    </div>
</div>
<?php foreach ($messages as $msg): ?>
<div class="card portal-card mb-2 <?= $msg['user_id'] ? 'border-start border-primary border-3' : '' ?>">
    <div class="card-body py-2">
        <small class="text-muted"><?= $msg['user_id'] ? 'Soporte (' . e($msg['username'] ?? '') . ')' : 'Tú' ?> — <?= e($msg['created_at']) ?></small>
        <p class="mb-0 mt-1"><?= nl2br(e($msg['message'])) ?></p>
    </div>
</div>
<?php endforeach; ?>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
