<?php ob_start(); ?>
<div class="mb-4">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Añadir usuarios por email</h4>
    <p class="text-muted mb-0">Asigna uno o varios emails a un servidor con un periodo de suscripción.</p>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/media-users/bulk">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Servidor *</label>
                    <select name="server_id" class="form-select" required>
                        <option value="">Seleccionar servidor...</option>
                        <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server->id ?>"><?= e($server->name) ?> (<?= e(strtoupper($server->type)) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Periodo *</label>
                    <select name="period" class="form-select" required>
                        <?php foreach ($periods as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $key === '1m' ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Emails *</label>
                    <textarea name="emails" class="form-control font-monospace" rows="10" required
                              placeholder="Un email por línea, o separados por comas&#10;usuario1@ejemplo.com&#10;usuario2@ejemplo.com"></textarea>
                    <div class="form-text">Si el email ya existe, se actualizará su servidor y fecha de expiración.</div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Añadir usuarios</button>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
