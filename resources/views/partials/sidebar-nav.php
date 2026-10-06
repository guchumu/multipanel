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

$mediaUsersListActive = $isExact('/media-users')
    || (preg_match('#^/media-users/[0-9a-f-]{36}#', $currentPath) === 1);

$mediaUsersToolsPaths = [
    '/media-users/create',
    '/media-users/activity',
    '/media-users/stream-violations',
    '/media-users/cut-logs',
    '/media-users/expiring',
    '/media-users/estimacion',
    '/media-users/broadcast',
    '/media-users/bulk',
    '/media-users/revisar',
];
$mediaUsersToolsActive = false;
foreach ($mediaUsersToolsPaths as $toolPath) {
    if ($currentPath === $toolPath || str_starts_with($currentPath, $toolPath . '/')) {
        $mediaUsersToolsActive = true;
        break;
    }
}

$settingsActive = $startsWith('/settings') || $startsWith('/import') || $startsWith('/media-users/limpieza');
$navIdSuffix = preg_replace('/[^a-z0-9_-]/i', '', (string) ($sidebarNavIdSuffix ?? 'main')) ?: 'main';
$mediaUsersToolsCollapseId = 'sidebarMediaUsersTools-' . $navIdSuffix;
?>
<ul class="nav flex-column p-2">
    <li class="nav-item"><a class="<?= $linkClass('/dashboard') ?>" href="/dashboard" title="<?= e(__('dashboard')) ?>"><i class="bi bi-speedometer2 me-2"></i><span class="sidebar-label"><?= __('dashboard') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/stats') ?>" href="/stats" title="<?= e(__('stats')) ?>"><i class="bi bi-bar-chart me-2"></i><span class="sidebar-label"><?= __('stats') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/servers') ?>" href="/servers" title="<?= e(__('servers')) ?>"><i class="bi bi-hdd-network me-2"></i><span class="sidebar-label"><?= __('servers') ?></span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/activity', true) ?>" href="/activity" title="En directo"><i class="bi bi-broadcast-pin me-2"></i><span class="sidebar-label">En directo</span></a></li>

    <li class="nav-item">
        <a class="nav-link text-white<?= $mediaUsersListActive ? ' active bg-primary rounded' : '' ?>" href="/media-users" title="Usuarios">
            <i class="bi bi-people me-2"></i><span class="sidebar-label">Usuarios</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-white-50<?= $mediaUsersToolsActive ? ' text-white' : '' ?>"
           href="#<?= e($mediaUsersToolsCollapseId) ?>"
           data-bs-toggle="collapse"
           role="button"
           aria-expanded="<?= $mediaUsersToolsActive ? 'true' : 'false' ?>"
           aria-controls="<?= e($mediaUsersToolsCollapseId) ?>"
           title="Más opciones de usuarios">
            <i class="bi bi-three-dots me-2"></i><span class="sidebar-label">Más usuarios</span>
            <i class="bi bi-chevron-down float-end small mt-1 sidebar-label"></i>
        </a>
        <div class="collapse<?= $mediaUsersToolsActive ? ' show' : '' ?>" id="<?= e($mediaUsersToolsCollapseId) ?>">
            <ul class="nav flex-column nav-children ms-3 ps-2 border-start border-secondary">
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/create') ?>" href="/media-users/create"><i class="bi bi-plus-lg me-2"></i><span class="sidebar-label">Nuevo usuario</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/activity') ?>" href="/media-users/activity"><i class="bi bi-clock-history me-2"></i><span class="sidebar-label">Actividad</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/stream-violations') ?>" href="/media-users/stream-violations"><i class="bi bi-exclamation-octagon me-2"></i><span class="sidebar-label">Incumplimientos streams</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/cut-logs') ?>" href="/media-users/cut-logs"><i class="bi bi-scissors me-2"></i><span class="sidebar-label">Log de cortes</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/expiring') ?>" href="/media-users/expiring"><i class="bi bi-hourglass-split me-2"></i><span class="sidebar-label">Vencimientos</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/estimacion') ?>" href="/media-users/estimacion"><i class="bi bi-calendar3 me-2"></i><span class="sidebar-label">Estimación mensual</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/broadcast') ?>" href="/media-users/broadcast"><i class="bi bi-megaphone me-2"></i><span class="sidebar-label">Mensaje masivo</span></a></li>
                <li class="nav-item"><a class="<?= $childLinkClass('/media-users/bulk') ?>" href="/media-users/bulk"><i class="bi bi-envelope-plus me-2"></i><span class="sidebar-label">Añadir emails</span></a></li>
            </ul>
        </div>
    </li>

    <li class="nav-item"><a class="<?= $linkClass('/peticiones') ?>" href="/peticiones" title="Peticiones"><i class="bi bi-film me-2"></i><span class="sidebar-label">Peticiones</span></a></li>
    <li class="nav-item"><a class="<?= $linkClass('/help/atencion-cliente') ?>" href="/help/atencion-cliente" title="Guía atención al cliente"><i class="bi bi-journal-bookmark me-2"></i><span class="sidebar-label">Guía atención cliente</span></a></li>

    <li class="nav-item mt-3"><small class="text-muted px-3 sidebar-label"><?= __('system') ?></small></li>
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
    <li class="nav-item mt-3"><small class="text-muted px-3 sidebar-label">Enlaces</small></li>
    <li class="nav-item"><a class="nav-link text-white-50" href="/portal/login" target="_blank" title="<?= e(__('portal')) ?>"><i class="bi bi-box-arrow-up-right me-2"></i><span class="sidebar-label"><?= __('portal') ?></span></a></li>
</ul>
