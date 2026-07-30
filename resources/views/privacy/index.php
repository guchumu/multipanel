<?php ob_start(); ?>
<h4 class="mb-4"><i class="bi bi-shield-lock me-2"></i>Privacidad / GDPR</h4>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Exportar mis datos</h6>
                <p class="text-muted small">Genera un archivo JSON con todos tus datos personales almacenados.</p>
                <form method="POST" action="/privacy/export"><?= csrf_field() ?><button class="btn btn-outline-primary">Solicitar exportación</button></form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm border-danger">
            <div class="card-body">
                <h6 class="text-danger">Eliminar datos (derecho al olvido)</h6>
                <p class="text-muted small">Esta acción anonimiza/elimina tus datos. Escribe DELETE para confirmar.</p>
                <form method="POST" action="/privacy/delete">
                    <?= csrf_field() ?>
                    <input name="confirm" class="form-control mb-2" placeholder="DELETE">
                    <button class="btn btn-outline-danger">Solicitar eliminación</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white"><strong>Solicitudes recientes</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Tipo</th><th>Estado</th><th>Fecha</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($requests)): ?>
            <tr><td colspan="4" class="text-muted text-center py-3">Sin solicitudes</td></tr>
            <?php else: ?>
            <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= e($r['type']) ?></td>
                <td><span class="badge bg-secondary"><?= e($r['status']) ?></span></td>
                <td><?= e($r['created_at']) ?></td>
                <td><?php if ($r['type'] === 'export' && $r['status'] === 'completed'): ?><a href="/privacy/<?= (int) $r['id'] ?>/download">Descargar</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
