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
    <div class="d-flex">
        <?php include base_path('resources/views/partials/sidebar.php'); ?>
        <div class="flex-grow-1">
            <?php include base_path('resources/views/partials/navbar.php'); ?>
            <main class="p-4">
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
