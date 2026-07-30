<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Soporte</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newTicket"><i class="bi bi-plus-lg"></i> Nuevo</button>
</div>

<div class="btn-group btn-group-sm mb-3">
    <a href="/tickets" class="btn btn-outline-secondary <?= !$currentStatus ? 'active' : '' ?>">Todos</a>
    <a href="/tickets?status=open" class="btn btn-outline-primary">Abiertos</a>
    <a href="/tickets?status=in_progress" class="btn btn-outline-info">En progreso</a>
    <a href="/tickets?status=closed" class="btn btn-outline-secondary">Cerrados</a>
</div>

<div class="card border-0 shadow-sm">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Asunto</th><th>Cliente</th><th>Estado</th><th>Prioridad</th><th>Asignado</th><th>Fecha</th></tr></thead>
        <tbody>
            <?php if (empty($tickets)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Sin tickets</td></tr>
            <?php else: ?>
            <?php foreach ($tickets as $t): ?>
            <tr>
                <td><a href="/tickets/<?= e($t['uuid']) ?>"><?= e($t['subject']) ?></a></td>
                <td class="small"><?= e($t['customer_email'] ?? '-') ?></td>
                <td><span class="badge bg-secondary"><?= e($t['status']) ?></span></td>
                <td><span class="badge bg-<?= $t['priority']==='urgent'?'danger':($t['priority']==='high'?'warning':'info') ?>"><?= e($t['priority']) ?></span></td>
                <td class="small"><?= e($t['assigned_name'] ?? '-') ?></td>
                <td class="small"><?= e($t['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal fade" id="newTicket" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="/tickets" class="modal-content">
        <?= csrf_field() ?>
        <div class="modal-header"><h5 class="modal-title">Nuevo ticket</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Asunto</label><input name="subject" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Mensaje</label><textarea name="message" class="form-control" rows="4" required></textarea></div>
            <div class="mb-3"><label class="form-label">Prioridad</label>
                <select name="priority" class="form-select"><option value="low">Baja</option><option value="medium" selected>Media</option><option value="high">Alta</option><option value="urgent">Urgente</option></select>
            </div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Crear</button></div>
    </form></div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
