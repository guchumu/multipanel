<?php
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isExact = static function (string $path) use ($currentPath): bool {
    return $currentPath === $path;
};
$startsWith = static function (string $prefix) use ($currentPath): bool {
    return str_starts_with($currentPath, $prefix);
};

$linkClass = static function (string $prefix, bool $exact = false) use ($currentPath, $isExact, $startsWith): string {
    if ($prefix === '/dashboard') {
        $active = $currentPath === '/dashboard' || $currentPath === '/';
    } elseif ($exact) {
        $active = $isExact($prefix);
    } else {
        $active = $startsWith($prefix);
    }

    return 'nav-link text-white' . ($active ? ' active bg-primary rounded' : '');
};

$childLinkClass = static function (string $path) use ($currentPath): string {
    $active = $currentPath === $path || str_starts_with($currentPath, $path . '/');

    return 'nav-link text-white-50 nav-link-child py-1' . ($active ? ' active text-white bg-primary rounded' : '');
};

$mediaUsersActive = $startsWith('/media-users') && !$startsWith('/media-users/limpieza');
$settingsActive = $startsWith('/settings') || $startsWith('/import') || $startsWith('/media-users/limpieza');
?>
<ul class="nav flex-column p-2">
    <li class="nav-item"><a class="<?= $linkClass('/dashboard') ?>" href="/dashboard" title="<?= e(__('dashboard')) ?>"><i class="bi bi-speedometer2 me-2"></i><span class="sidebar-label"><?= __('dashboard') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/stats') ?>" href="/stats" title="<?= e(__('stats')) ?>"><i class="bi bi-bar-chart me-2"></i><span class="sidebar-label"><?= __('stats') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/servers') ?>" href="/servers" title="<?= e(__('servers')) ?>"><i class="bi bi-hdd-network me-2"></i><span class="sidebar-label"><?= __('servers') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/activity', true) ?>" href="/activity" title="En directo"><i class="bi bi-broadcast-pin me-2"></i><span class="sidebar-label">En directo</span></a></li>

    <li class="nav-item">
        <a class="<?= $linkClass('/media-users', true) ?><?= $mediaUsersActive && !$isExact('/media-users') ? ' text-white' : '' ?>" href="/media-users" title="<?= e(__('media_users')) ?>">
            <i class="bi bi-people me-2"></i><span class="sidebar-label"><?= __('media_users') ?></span>
        </a>
        <ul class="nav flex-column nav-children ms-3 ps-2 border-start border-secondary">
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/create') ?>" href="/media-users/create"><i class="bi bi-plus-lg me-2"></i><span class="sidebar-label">Nuevo usuario</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/activity') ?>" href="/media-users/activity"><i class="bi bi-clock-history me-2"></i><span class="sidebar-label">Actividad</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/stream-violations') ?>" href="/media-users/stream-violations"><i class="bi bi-exclamation-octagon me-2"></i><span class="sidebar-label">Incumplimientos streams</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/expiring') ?>" href="/media-users/expiring"><i class="bi bi-hourglass-split me-2"></i><span class="sidebar-label">Vencimientos</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/estimacion') ?>" href="/media-users/estimacion"><i class="bi bi-calendar3 me-2"></i><span class="sidebar-label">Estimación mensual</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/broadcast') ?>" href="/media-users/broadcast"><i class="bi bi-megaphone me-2"></i><span class="sidebar-label">Mensaje masivo</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/bulk') ?>" href="/media-users/bulk"><i class="bi bi-envelope-plus me-2"></i><span class="sidebar-label">Añadir emails</span></a></li>
        </ul>
    </li>

    <li class="nav-item"><a class="<?= $linkClass('/peticiones') ?>" href="/peticiones" title="Peticiones"><i class="bi bi-film me-2"></i><span class="sidebar-label">Peticiones</span></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3 sidebar-label"><?= __('management') ?></small></li>
    <li class="nav-item"><a class="<?= $linkClass('/integrations') ?>" href="/integrations" title="<?= e(__('integrations')) ?>"><i class="bi bi-plug me-2"></i><span class="sidebar-label"><?= __('integrations') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/automation') ?>" href="/automation" title="<?= e(__('automation')) ?>"><i class="bi bi-lightning me-2"></i><span class="sidebar-label"><?= __('automation') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/customers') ?>" href="/customers" title="<?= e(__('customers')) ?>"><i class="bi bi-person-vcard me-2"></i><span class="sidebar-label"><?= __('customers') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/billing') ?>" href="/billing" title="<?= e(__('billing')) ?>"><i class="bi bi-credit-card me-2"></i><span class="sidebar-label"><?= __('billing') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/tickets') ?>" href="/tickets" title="<?= e(__('support')) ?>"><i class="bi bi-headset me-2"></i><span class="sidebar-label"><?= __('support') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/invoices') ?>" href="/invoices" title="<?= e(__('invoices')) ?>"><i class="bi bi-receipt me-2"></i><span class="sidebar-label"><?= __('invoices') ?></span></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3 sidebar-label"><?= __('security_section') ?></small></li>
    <li class="nav-item"><a class="<?= $linkClass('/roles') ?>" href="/roles" title="<?= e(__('roles')) ?>"><i class="bi bi-shield-check me-2"></i><span class="sidebar-label"><?= __('roles') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/api-keys') ?>" href="/api-keys" title="<?= e(__('api_keys')) ?>"><i class="bi bi-key me-2"></i><span class="sidebar-label"><?= __('api_keys') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/security') ?>" href="/security" title="<?= e(__('security')) ?>"><i class="bi bi-shield-exclamation me-2"></i><span class="sidebar-label"><?= __('security') ?></span></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3 sidebar-label"><?= __('system') ?></small></li>
    <li class="nav-item"><a class="<?= $linkClass('/webhooks') ?>" href="/webhooks" title="<?= e(__('webhooks')) ?>"><i class="bi bi-broadcast me-2"></i><span class="sidebar-label"><?= __('webhooks') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/privacy') ?>" href="/privacy" title="<?= e(__('privacy')) ?>"><i class="bi bi-shield-lock me-2"></i><span class="sidebar-label"><?= __('privacy') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/logs') ?>" href="/logs" title="<?= e(__('logs')) ?>"><i class="bi bi-journal-text me-2"></i><span class="sidebar-label"><?= __('logs') ?></span></a></li>

    <li class="nav-item">
        <a class="<?= $linkClass('/settings', true) ?><?= $settingsActive && !$isExact('/settings') ? ' text-white' : '' ?>" href="/settings" title="<?= e(__('settings')) ?>">
            <i class="bi bi-gear me-2"></i><span class="sidebar-label"><?= __('settings') ?></span>
        </a>
        <ul class="nav flex-column nav-children ms-3 ps-2 border-start border-secondary">
            <li class="nav-item"><a class="<?= $childLinkClass('/settings/notifications') ?>" href="/settings/notifications"><i class="bi bi-chat-dots me-2"></i><span class="sidebar-label">Mensajes a usuarios</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/settings/stop-messages') ?>" href="/settings/stop-messages"><i class="bi bi-chat-left-text me-2"></i><span class="sidebar-label">Mensajes al detener</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/settings/stream-limits') ?>" href="/settings/stream-limits"><i class="bi bi-collection-play me-2"></i><span class="sidebar-label">Límite de streams</span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/import') ?>" href="/import"><i class="bi bi-upload me-2"></i><span class="sidebar-label"><?= __('import_export') ?></span></a></li>
            <li class="nav-item"><a class="<?= $childLinkClass('/media-users/limpieza') ?>" href="/media-users/limpieza"><i class="bi bi-recycle me-2"></i><span class="sidebar-label">Limpieza / reinicio</span></a></li>
        </ul>
    </li>

    <li class="nav-item"><a class="<?= $linkClass('/backups') ?>" href="/backups" title="<?= e(__('backups')) ?>"><i class="bi bi-cloud-arrow-up me-2"></i><span class="sidebar-label"><?= __('backups') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/updater') ?>" href="/updater" title="<?= e(__('updates')) ?>"><i class="bi bi-arrow-up-circle me-2"></i><span class="sidebar-label"><?= __('updates') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/diagnostics') ?>" href="/diagnostics" title="<?= e(__('diagnostics')) ?>"><i class="bi bi-heart-pulse me-2"></i><span class="sidebar-label"><?= __('diagnostics') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/plugins') ?>" href="/plugins" title="<?= e(__('plugins')) ?>"><i class="bi bi-puzzle me-2"></i><span class="sidebar-label"><?= __('plugins') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/tenants') ?>" href="/tenants" title="<?= e(__('tenants')) ?>"><i class="bi bi-building me-2"></i><span class="sidebar-label"><?= __('tenants') ?></span></a></li>
    <li class="nav-item mt-3"><small class="text-muted px-3 sidebar-label">Enlaces</small></li>
    <li class="nav-item"><a class="nav-link text-white-50" href="/portal/login" target="_blank" title="<?= e(__('portal')) ?>"><i class="bi bi-box-arrow-up-right me-2"></i><span class="sidebar-label"><?= __('portal') ?></span></a></li>
    <li class="nav-item"><a class="nav-link text-white-50" href="/api/docs" target="_blank" title="<?= e(__('api_docs')) ?>"><i class="bi bi-code-slash me-2"></i><span class="sidebar-label"><?= __('api_docs') ?></span></a></li>
</ul>
