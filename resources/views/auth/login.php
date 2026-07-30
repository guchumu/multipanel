<?php ob_start(); ?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
    <div class="card shadow-sm border-0" style="width: 400px;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-collection-play text-primary" style="font-size: 3rem;"></i>
                <h4 class="mt-2">MultiPanel ERP</h4>
                <p class="text-muted small">Gestión profesional Plex & Jellyfin</p>
            </div>

            <?php
            use Core\Session;
            $error = Session::getInstance()->getFlash('error');
            if ($error): ?>
            <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="/login">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e(old('email')) ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Contraseña</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Iniciar sesión</button>
            </form>

            <?php if (!empty($oauthProviders)): ?>
            <div class="position-relative my-4">
                <hr>
                <span class="position-absolute top-50 start-50 translate-middle bg-white px-2 text-muted small">o continuar con</span>
            </div>
            <div class="d-grid gap-2">
                <?php foreach ($oauthProviders as $slug => $provider): ?>
                <a href="/auth/oauth/<?= e($slug) ?>" class="btn btn-outline-secondary">
                    <i class="bi <?= e($provider['icon'] ?? 'bi-box-arrow-in-right') ?> me-2"></i><?= e($provider['label'] ?? ucfirst($slug)) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$user = null;
include base_path('resources/views/layouts/app.php');
