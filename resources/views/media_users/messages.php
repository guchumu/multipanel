<?php ob_start(); ?>
<div class="mb-4">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver a usuarios</a>
    <h4 class="mt-2">Historial de mensajes</h4>
    <p class="text-muted small mb-0"><?= e($user->display_name ?? $user->username) ?> · <?= e($user->email ?? '') ?></p>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Canal</th>
                    <th>Mensaje</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Sin mensajes registrados</td></tr>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <tr>
                    <td class="small text-nowrap"><?= e($msg['sent_at']) ?></td>
                    <td><span class="badge bg-secondary"><?= e($msg['message_type']) ?></span></td>
                    <td class="small"><?= e($msg['channel']) ?></td>
                    <td class="small" style="max-width: 420px; white-space: pre-wrap;"><?= e($msg['body']) ?></td>
                    <td><span class="badge bg-<?= $msg['status'] === 'sent' ? 'success' : 'danger' ?>"><?= e($msg['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
