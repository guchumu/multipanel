<aside class="sidebar bg-dark text-white d-none d-lg-flex flex-column flex-shrink-0">
    <div class="p-3 border-bottom border-secondary">
        <h5 class="mb-0"><i class="bi bi-collection-play me-2"></i>MultiPanel</h5>
        <small class="text-muted">ERP v<?= e(config('app.version')) ?></small>
    </div>
    <div class="overflow-auto flex-grow-1">
        <?php include base_path('resources/views/partials/sidebar-nav.php'); ?>
    </div>
</aside>
