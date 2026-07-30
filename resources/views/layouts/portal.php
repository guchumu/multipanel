<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'Portal') ?> - MultiPanel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('css/portal.css') ?>" rel="stylesheet">
</head>
<body class="portal-body">
    <?php if (isset($portalUser)): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="/portal"><i class="bi bi-play-circle me-2"></i>Mi Portal</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="/portal">Inicio</a>
                <a class="nav-link" href="/portal/subscription">Suscripción</a>
                <a class="nav-link" href="/portal/tickets">Soporte</a>
                <a class="nav-link" href="/portal/profile">Perfil</a>
                <form action="/portal/logout" method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <button class="nav-link btn btn-link">Salir</button>
                </form>
            </div>
        </div>
    </nav>
    <div class="container py-4">
        <?php
        use Core\Session;
        if ($err = Session::getInstance()->getFlash('error')): ?>
        <div class="alert alert-danger"><?= e($err) ?></div>
        <?php endif;
        if ($ok = Session::getInstance()->getFlash('success')): ?>
        <div class="alert alert-success"><?= e($ok) ?></div>
        <?php endif; ?>
        <?= $content ?? '' ?>
    </div>
    <?php else: ?>
    <?= $content ?? '' ?>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
