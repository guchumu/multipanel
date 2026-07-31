<!DOCTYPE html>
<html lang="<?= e(\Core\Language::getLocale()) ?>" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'MultiPanel') ?> - MultiPanel ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('css/app.css') ?>" rel="stylesheet">
</head>
<body>
    <?php if (isset($user)): ?>
    <div class="offcanvas offcanvas-start bg-dark text-white d-lg-none" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
        <div class="offcanvas-header border-bottom border-secondary">
            <h5 class="offcanvas-title" id="sidebarOffcanvasLabel">
                <i class="bi bi-collection-play me-2"></i>MultiPanel
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body p-0">
            <?php include base_path('resources/views/partials/sidebar-nav.php'); ?>
        </div>
    </div>

    <div class="app-shell d-flex min-vh-100">
        <?php include base_path('resources/views/partials/sidebar.php'); ?>
        <div class="app-main flex-grow-1 d-flex flex-column min-vw-0">
            <?php include base_path('resources/views/partials/navbar.php'); ?>
            <main class="app-content flex-grow-1 p-3 p-lg-4">
                <?php include base_path('resources/views/partials/alerts.php'); ?>
                <?= $content ?? '' ?>
            </main>
        </div>
    </div>
    <?php else: ?>
    <?= $content ?? '' ?>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="<?= asset('js/app.js') ?>"></script>
    <script src="<?= asset('js/realtime.js') ?>"></script>
    <?= $scripts ?? '' ?>
</body>
</html>
