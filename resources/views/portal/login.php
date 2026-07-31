<?php ob_start(); ?>
<div class="card portal-login-card shadow">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <i class="bi bi-play-circle text-primary" style="font-size:3rem"></i>
            <h4 class="mt-2">Portal Cliente</h4>
            <p class="text-muted small">Accede a tu cuenta de streaming</p>
        </div>
        <?php if ($e = \Core\Session::getInstance()->getFlash('error')): ?>
        <div class="alert alert-danger py-2"><?= e($e) ?></div>
        <?php endif; ?>
        <form method="POST" action="/portal/login">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Usuario o email</label>
                <input name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button class="btn btn-primary w-100">Entrar</button>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
