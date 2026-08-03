<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Actividad de usuarios</h4>
        <p class="text-muted small mb-0">Cambios de fechas, activaciones, suspensiones y más</p>
    </div>
    <a href="/media-users/broadcast" class="btn btn-outline-primary btn-sm"><i class="bi bi-megaphone me-1"></i>Mensaje masivo</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Acción</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($events)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Sin actividad registrada</td></tr>
                <?php else: ?>
                <?php foreach ($events as $ev): ?>
                <tr>
                    <td class="small text-nowrap"><?= e($ev['at']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= e($ev['label']) ?></span></td>
                    <td><?= e($ev['user']) ?></td>
                    <td class="small"><?= e($ev['email']) ?></td>
                    <td><a href="/media-users/<?= e($ev['uuid']) ?>" class="btn btn-sm btn-outline-primary">Ver</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
