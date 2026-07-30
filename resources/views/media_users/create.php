<?php ob_start(); ?>
<div class="mb-4">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Nuevo usuario media</h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/media-users">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nombre visible</label>
                    <input type="text" name="display_name" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contraseña</label>
                    <input type="text" name="password" class="form-control" placeholder="Auto-generada si vacío">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max streams</label>
                    <input type="number" name="max_streams" class="form-control" value="1" min="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max dispositivos</label>
                    <input type="number" name="max_devices" class="form-control" value="5" min="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha expiración</label>
                    <input type="datetime-local" name="expires_at" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Notas internas</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Crear usuario</button>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
