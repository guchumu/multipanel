<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'Portal') ?> · MultiPanel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('css/portal.css') ?>?v=<?= @filemtime(public_path('assets/css/portal.css')) ?: '1' ?>" rel="stylesheet">
</head>
<body class="portal-body">
    <?php if (isset($portalUser)): ?>
    <?php $nav = $navActive ?? ''; ?>
    <nav class="navbar navbar-expand-lg navbar-dark portal-nav">
        <div class="container">
            <a class="navbar-brand portal-brand" href="/portal">
                <span class="portal-brand-mark"><i class="bi bi-play-fill"></i></span>
                <span class="portal-brand-text">MultiPanel</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav" aria-controls="portalNav" aria-expanded="false" aria-label="Menú">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="portalNav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-1 py-2 py-lg-0">
                    <a class="nav-link<?= $nav === 'home' ? ' active' : '' ?>" href="/portal">Inicio</a>
                    <a class="nav-link<?= $nav === 'pay' ? ' active' : '' ?>" href="/portal/subscription">Comprar</a>
                    <a class="nav-link<?= $nav === 'peticiones' ? ' active' : '' ?>" href="/portal/peticiones">Pedir peli</a>
                    <a class="nav-link<?= $nav === 'tickets' ? ' active' : '' ?>" href="/portal/tickets">Ayuda</a>
                    <a class="nav-link<?= $nav === 'profile' ? ' active' : '' ?>" href="/portal/profile">Mi ficha</a>
                    <form action="/portal/logout" method="POST" class="d-lg-inline ms-lg-2 mt-2 mt-lg-0">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-light btn-sm w-100" type="submit">Salir</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>
    <div class="container py-3 py-md-4 portal-container">
        <?php if ($err = \Core\Session::getInstance()->getFlash('error')): ?>
        <div class="alert alert-danger portal-alert"><?= e($err) ?></div>
        <?php endif;
        if ($ok = \Core\Session::getInstance()->getFlash('success')): ?>
        <div class="alert alert-success portal-alert"><?= e($ok) ?></div>
        <?php endif; ?>
        <?= $content ?? '' ?>
    </div>
    <footer class="portal-footer text-center text-white-50 small pb-4">
        Tu cine en casa
    </footer>
    <?php else: ?>
    <?= $content ?? '' ?>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?= $scripts ?? '' ?>
</body>
</html>
