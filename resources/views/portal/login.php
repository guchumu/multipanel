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
                    <label class="form-label" for="portal-username">Email</label>
                    <input id="portal-username" name="username" type="email" class="form-control form-control-lg" required autofocus autocomplete="username" placeholder="tu@email.com">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="portal-password">Contraseña</label>
                    <input id="portal-password" type="password" name="password" class="form-control form-control-lg" required autocomplete="current-password">
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Entrar</button>
            </form>

            <hr class="my-4">

            <h2 class="h6 mb-2">¿No recuerdas la contraseña?</h2>
            <p class="text-muted small mb-3">Introduce tu email y te la enviamos por Telegram (si tienes el chat vinculado).</p>
            <form method="POST" action="/portal/login/send-password" class="d-grid gap-2">
                <?= csrf_field() ?>
                <input type="email" name="email" class="form-control" required placeholder="tu@email.com" autocomplete="email">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-telegram me-1"></i>Recibir contraseña por Telegram
                </button>
            </form>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
