<?php
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
<nav class="sidebar bg-dark text-white" style="width: 260px; min-height: 100vh;">
    <div class="p-3 border-bottom border-secondary">
        <h5 class="mb-0"><i class="bi bi-collection-play me-2"></i>MultiPanel</h5>
        <small class="text-muted">ERP v<?= e(config('app.version')) ?></small>
    </div>
    <ul class="nav flex-column p-2">
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/dashboard') ? 'active bg-primary rounded' : '' ?>" href="/dashboard"><i class="bi bi-speedometer2 me-2"></i><?= __('dashboard') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/stats') ? 'active bg-primary rounded' : '' ?>" href="/stats"><i class="bi bi-bar-chart me-2"></i><?= __('stats') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/servers') ? 'active bg-primary rounded' : '' ?>" href="/servers"><i class="bi bi-hdd-network me-2"></i><?= __('servers') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/media-users') ? 'active bg-primary rounded' : '' ?>" href="/media-users"><i class="bi bi-people me-2"></i><?= __('media_users') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/import') ? 'active bg-primary rounded' : '' ?>" href="/import"><i class="bi bi-upload me-2"></i><?= __('import_export') ?></a></li>
        <li class="nav-item mt-3"><small class="text-muted px-3"><?= __('management') ?></small></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/integrations') ? 'active bg-primary rounded' : '' ?>" href="/integrations"><i class="bi bi-plug me-2"></i><?= __('integrations') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/automation') ? 'active bg-primary rounded' : '' ?>" href="/automation"><i class="bi bi-lightning me-2"></i><?= __('automation') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/customers') ? 'active bg-primary rounded' : '' ?>" href="/customers"><i class="bi bi-person-vcard me-2"></i><?= __('customers') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/billing') ? 'active bg-primary rounded' : '' ?>" href="/billing"><i class="bi bi-credit-card me-2"></i><?= __('billing') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/tickets') ? 'active bg-primary rounded' : '' ?>" href="/tickets"><i class="bi bi-headset me-2"></i><?= __('support') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/invoices') ? 'active bg-primary rounded' : '' ?>" href="/invoices"><i class="bi bi-receipt me-2"></i><?= __('invoices') ?></a></li>
        <li class="nav-item mt-3"><small class="text-muted px-3"><?= __('security_section') ?></small></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/roles') ? 'active bg-primary rounded' : '' ?>" href="/roles"><i class="bi bi-shield-check me-2"></i><?= __('roles') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/api-keys') ? 'active bg-primary rounded' : '' ?>" href="/api-keys"><i class="bi bi-key me-2"></i><?= __('api_keys') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/security') ? 'active bg-primary rounded' : '' ?>" href="/security"><i class="bi bi-shield-exclamation me-2"></i><?= __('security') ?></a></li>
        <li class="nav-item mt-3"><small class="text-muted px-3"><?= __('system') ?></small></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/webhooks') ? 'active bg-primary rounded' : '' ?>" href="/webhooks"><i class="bi bi-broadcast me-2"></i><?= __('webhooks') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/privacy') ? 'active bg-primary rounded' : '' ?>" href="/privacy"><i class="bi bi-shield-lock me-2"></i><?= __('privacy') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/logs') ? 'active bg-primary rounded' : '' ?>" href="/logs"><i class="bi bi-journal-text me-2"></i><?= __('logs') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/settings') ? 'active bg-primary rounded' : '' ?>" href="/settings"><i class="bi bi-gear me-2"></i><?= __('settings') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/backups') ? 'active bg-primary rounded' : '' ?>" href="/backups"><i class="bi bi-cloud-arrow-up me-2"></i><?= __('backups') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/updater') ? 'active bg-primary rounded' : '' ?>" href="/updater"><i class="bi bi-arrow-up-circle me-2"></i><?= __('updates') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/diagnostics') ? 'active bg-primary rounded' : '' ?>" href="/diagnostics"><i class="bi bi-heart-pulse me-2"></i><?= __('diagnostics') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/plugins') ? 'active bg-primary rounded' : '' ?>" href="/plugins"><i class="bi bi-puzzle me-2"></i><?= __('plugins') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white <?= str_starts_with($currentPath, '/tenants') ? 'active bg-primary rounded' : '' ?>" href="/tenants"><i class="bi bi-building me-2"></i><?= __('tenants') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white-50" href="/portal/login" target="_blank"><i class="bi bi-box-arrow-up-right me-2"></i><?= __('portal') ?></a></li>
        <li class="nav-item"><a class="nav-link text-white-50" href="/api/docs" target="_blank"><i class="bi bi-code-slash me-2"></i><?= __('api_docs') ?></a></li>
    </ul>
</nav>
