<?php ob_start(); ?>
<div class="portal-login-wrap">
    <div class="card portal-login-card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="portal-login-mark mx-auto mb-3"><i class="bi bi-play-fill"></i></div>
                <h1 class="h4 mb-1">MultiPanel</h1>
                <p class="text-muted small mb-0">Accede a tu área de cliente</p>
            </div>
            <?php if ($e = \Core\Session::getInstance()->getFlash('error')): ?>
            <div class="alert alert-danger py-2"><?= e($e) ?></div>
            <?php endif; ?>
            <?php if ($ok = \Core\Session::getInstance()->getFlash('success')): ?>
            <div class="alert alert-success py-2"><?= e($ok) ?></div>
            <?php endif; ?>
            <form method="POST" action="/portal/login" autocomplete="on">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="portal-username">Usuario o email</label>
                    <input id="portal-username" name="username" class="form-control form-control-lg" required autofocus autocomplete="username">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="portal-password">Contraseña</label>
                    <input id="portal-password" type="password" name="password" class="form-control form-control-lg" required autocomplete="current-password">
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Entrar</button>
            </form>
            <p class="text-muted small text-center mt-4 mb-0">Si no recuerdas la contraseña, contacta con tu administrador.</p>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
