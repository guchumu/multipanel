<aside class="sidebar bg-dark text-white d-none d-lg-flex flex-column flex-shrink-0">
    <div class="p-3 border-bottom border-secondary sidebar-brand">
        <h5 class="mb-0 d-flex align-items-center">
            <i class="bi bi-collection-play me-2"></i><span class="sidebar-label">MultiPanel</span>
        </h5>
        <small class="text-muted sidebar-label">ERP v<?= e(config('app.version')) ?></small>
    </div>
    <div class="overflow-auto flex-grow-1">
        <?php include base_path('resources/views/partials/sidebar-nav.php'); ?>
    </div>
</aside>
