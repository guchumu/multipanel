<?php ob_start(); ?>
<div class="mb-4">
    <a href="/tickets" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Volver</a>
    <h4 class="mt-2"><?= e($ticket['subject']) ?></h4>
    <span class="badge bg-secondary"><?= e($ticket['status']) ?></span>
    <span class="badge bg-info"><?= e($ticket['priority']) ?></span>
</div>

<?php foreach ($messages as $msg): ?>
<div class="card border-0 shadow-sm mb-2 <?= $msg['is_internal'] ? 'border-warning border-start border-3' : '' ?>">
    <div class="card-body py-2">
        <small class="text-muted"><?= e($msg['username'] ?? $msg['customer_email'] ?? 'Cliente') ?> — <?= e($msg['created_at']) ?>
        <?= $msg['is_internal'] ? '<span class="badge bg-warning text-dark">Interno</span>' : '' ?></small>
        <p class="mb-0 mt-1"><?= nl2br(e($msg['message'])) ?></p>
    </div>
</div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
        <form method="POST" action="/tickets/<?= e($ticket['uuid']) ?>/reply">
            <?= csrf_field() ?>
            <textarea name="message" class="form-control mb-2" rows="3" placeholder="Escribe una respuesta..." required></textarea>
            <div class="d-flex gap-2 align-items-center">
                <select name="status" class="form-select form-select-sm" style="width:auto">
                    <option value="in_progress">En progreso</option>
                    <option value="waiting">Esperando cliente</option>
                    <option value="resolved">Resuelto</option>
                </select>
                <div class="form-check ms-2"><input type="checkbox" name="is_internal" class="form-check-input" id="internal"><label for="internal" class="form-check-label small">Nota interna</label></div>
                <button class="btn btn-primary btn-sm ms-auto">Responder</button>
                <?php if ($ticket['status'] !== 'closed'): ?>
                <a href="/tickets/<?= e($ticket['uuid']) ?>/close" class="btn btn-outline-secondary btn-sm" onclick="event.preventDefault(); fetch(this.href,{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}}).then(()=>location.reload())">Cerrar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
