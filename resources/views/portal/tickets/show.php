<?php
$statusEs = static function (string $s): string {
    return match ($s) {
        'open' => 'Abierto',
        'in_progress' => 'En curso',
        'waiting' => 'En espera',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
        default => $s,
    };
};
$priorityEs = static function (string $s): string {
    return match ($s) {
        'low' => 'Baja',
        'medium' => 'Media',
        'high' => 'Alta',
        'urgent' => 'Urgente',
        default => $s,
    };
};
$closed = in_array((string) ($ticket['status'] ?? ''), ['closed', 'resolved'], true);
ob_start();
?>
<p class="mb-2"><a class="link-light small" href="/portal/tickets">← Mis tickets</a></p>
<h1 class="portal-page-title"><?= e($ticket['subject']) ?></h1>
<div class="mb-3">
    <span class="badge text-bg-secondary"><?= e($statusEs((string) $ticket['status'])) ?></span>
    <span class="badge text-bg-light text-dark ms-1"><?= e($priorityEs((string) ($ticket['priority'] ?? ''))) ?></span>
</div>

<?php foreach ($messages as $msg): ?>
<div class="card portal-card mb-2 <?= !empty($msg['user_id']) ? 'portal-msg--staff' : 'portal-msg--client' ?>">
    <div class="card-body py-2">
        <small class="text-muted">
            <?= !empty($msg['user_id']) ? 'Soporte' : 'Tú' ?>
            — <?= e(substr((string) ($msg['created_at'] ?? ''), 0, 16)) ?>
        </small>
        <p class="mb-0 mt-1"><?= nl2br(e($msg['message'])) ?></p>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$closed): ?>
<div class="card portal-card mt-3">
    <div class="card-body">
        <h2 class="portal-section-title">Responder</h2>
        <form method="POST" action="/portal/tickets/<?= e($ticket['uuid']) ?>/reply">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label visually-hidden" for="reply-message">Mensaje</label>
                <textarea id="reply-message" name="message" class="form-control" rows="3" required placeholder="Escribe tu respuesta…"></textarea>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Enviar mensaje</button>
        </form>
    </div>
</div>
<?php else: ?>
<p class="text-white-50 small mt-3">Este ticket está cerrado. Si necesitas ayuda, <a class="link-light" href="/portal/tickets/create">abre uno nuevo</a>.</p>
<?php endif; ?>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
