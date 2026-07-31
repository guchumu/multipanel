<?php
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$linkClass = static function (string $prefix) use ($currentPath): string {
    $active = $prefix === '/dashboard'
        ? ($currentPath === '/dashboard' || $currentPath === '/')
        : str_starts_with($currentPath, $prefix);

    return 'nav-link text-white' . ($active ? ' active bg-primary rounded' : '');
};
?>
<ul class="nav flex-column p-2">
    <li class="nav-item"><a class="<?= $linkClass('/dashboard') ?>" href="/dashboard"><i class="bi bi-speedometer2 me-2"></i><?= __('dashboard') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/stats') ?>" href="/stats"><i class="bi bi-bar-chart me-2"></i><?= __('stats') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/servers') ?>" href="/servers"><i class="bi bi-hdd-network me-2"></i><?= __('servers') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/activity') ?>" href="/activity"><i class="bi bi-broadcast-pin me-2"></i>En directo</a></li>
    <li class="nav-item"><a class="<?= $linkClass('/media-users') ?>" href="/media-users"><i class="bi bi-people me-2"></i><?= __('media_users') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/media-users/bulk') ?>" href="/media-users/bulk"><i class="bi bi-envelope-plus me-2"></i>Añadir emails</a></li>
    <li class="nav-item"><a class="<?= $linkClass('/import') ?>" href="/import"><i class="bi bi-upload me-2"></i><?= __('import_export') ?></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3"><?= __('management') ?></small></li>
    <li class="nav-item"><a class="<?= $linkClass('/integrations') ?>" href="/integrations"><i class="bi bi-plug me-2"></i><?= __('integrations') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/automation') ?>" href="/automation"><i class="bi bi-lightning me-2"></i><?= __('automation') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/customers') ?>" href="/customers"><i class="bi bi-person-vcard me-2"></i><?= __('customers') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/billing') ?>" href="/billing"><i class="bi bi-credit-card me-2"></i><?= __('billing') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/tickets') ?>" href="/tickets"><i class="bi bi-headset me-2"></i><?= __('support') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/invoices') ?>" href="/invoices"><i class="bi bi-receipt me-2"></i><?= __('invoices') ?></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3"><?= __('security_section') ?></small></li>
    <li class="nav-item"><a class="<?= $linkClass('/roles') ?>" href="/roles"><i class="bi bi-shield-check me-2"></i><?= __('roles') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/api-keys') ?>" href="/api-keys"><i class="bi bi-key me-2"></i><?= __('api_keys') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/security') ?>" href="/security"><i class="bi bi-shield-exclamation me-2"></i><?= __('security') ?></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3"><?= __('system') ?></small></li>
    <li class="nav-item"><a class="<?= $linkClass('/webhooks') ?>" href="/webhooks"><i class="bi bi-broadcast me-2"></i><?= __('webhooks') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/privacy') ?>" href="/privacy"><i class="bi bi-shield-lock me-2"></i><?= __('privacy') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/logs') ?>" href="/logs"><i class="bi bi-journal-text me-2"></i><?= __('logs') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/settings') ?>" href="/settings"><i class="bi bi-gear me-2"></i><?= __('settings') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/backups') ?>" href="/backups"><i class="bi bi-cloud-arrow-up me-2"></i><?= __('backups') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/updater') ?>" href="/updater"><i class="bi bi-arrow-up-circle me-2"></i><?= __('updates') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/diagnostics') ?>" href="/diagnostics"><i class="bi bi-heart-pulse me-2"></i><?= __('diagnostics') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/plugins') ?>" href="/plugins"><i class="bi bi-puzzle me-2"></i><?= __('plugins') ?></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/tenants') ?>" href="/tenants"><i class="bi bi-building me-2"></i><?= __('tenants') ?></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3">Enlaces</small></li>
    <li class="nav-item"><a class="nav-link text-white-50" href="/portal/login" target="_blank"><i class="bi bi-box-arrow-up-right me-2"></i><?= __('portal') ?></a></li>
    <li class="nav-item"><a class="nav-link text-white-50" href="/api/docs" target="_blank"><i class="bi bi-code-slash me-2"></i><?= __('api_docs') ?></a></li>
</ul>
