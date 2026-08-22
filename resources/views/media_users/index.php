<?php
$currentSort = $currentSort ?? null;
$currentDir = ($currentDir ?? 'desc') === 'asc' ? 'asc' : 'desc';
$emptyFilters = is_array($emptyFilters ?? null) ? $emptyFilters : [];
$defaultMaxStreams = max(1, (int) ($defaultMaxStreams ?? 1));
$currentOnServer = $currentOnServer ?? null;

$queryBase = static function (
    ?string $status,
    ?int $serverId,
    ?bool $onServer = null,
    ?string $sort = null,
    ?string $dir = null,
    array $empty = [],
) {
    $params = [];
    if ($status) {
        $params['status'] = $status;
    }
    if ($serverId) {
        $params['server_id'] = $serverId;
    }
    if ($onServer !== null) {
        $params['on_server'] = $onServer ? '1' : '0';
    }
    if ($sort) {
        $params['sort'] = $sort;
        $params['dir'] = ($dir === 'asc') ? 'asc' : 'desc';
    }
    if ($empty !== []) {
        $params['filter_empty'] = implode(',', $empty);
    }
    return $params !== [] ? '?' . http_build_query($params) : '';
};

/** @param array{status?: ?string, server_id?: ?int, on_server?: ?bool, sort?: ?string, dir?: ?string, empty?: list<string>} $over */
$withFilters = static function (array $over = []) use (
    $queryBase,
    $currentStatus,
    $currentServerId,
    $currentOnServer,
    $currentSort,
    $currentDir,
    $emptyFilters
): string {
    $status = array_key_exists('status', $over) ? $over['status'] : $currentStatus;
    $serverId = array_key_exists('server_id', $over)
        ? $over['server_id']
        : ($currentServerId ? (int) $currentServerId : null);
    $onServer = array_key_exists('on_server', $over) ? $over['on_server'] : $currentOnServer;
    $sort = array_key_exists('sort', $over) ? $over['sort'] : $currentSort;
    $dir = array_key_exists('dir', $over) ? $over['dir'] : $currentDir;
    $empty = array_key_exists('empty', $over) ? (array) $over['empty'] : $emptyFilters;

    return $queryBase($status, $serverId, $onServer, $sort, $dir, $empty);
};

$toggleEmpty = static function (string $key) use ($emptyFilters, $withFilters): string {
    $next = $emptyFilters;
    if (in_array($key, $next, true)) {
        $next = array_values(array_filter($next, static fn ($v) => $v !== $key));
    } else {
        $next[] = $key;
    }
    return '/media-users' . $withFilters(['empty' => $next]);
};

$sortUrl = static function (string $col) use ($currentSort, $currentDir, $withFilters): string {
    $nextDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    return '/media-users' . $withFilters(['sort' => $col, 'dir' => $nextDir]);
};

$sortIcon = static function (string $col) use ($currentSort, $currentDir): string {
    if ($currentSort !== $col) {
        return '<i class="bi bi-arrow-down-up ms-1 opacity-25" aria-hidden="true"></i>';
    }
    $icon = $currentDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
    return '<i class="bi ' . $icon . ' ms-1" aria-hidden="true"></i>';
};

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'active' => 'bg-success',
        'suspended' => 'bg-warning text-dark',
        'pending' => 'bg-secondary',
        default => 'bg-light text-dark border',
    };
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'active' => 'Activo',
        'suspended' => 'Suspendido',
        'pending' => 'Pendiente',
        'invited' => 'Invitado',
        'inactive' => 'Inactivo',
        default => ucfirst($status),
    };
};

$membershipBadge = static function ($onServer): array {
    if ($onServer === null || $onServer === '') {
        return ['label' => 'Sin sync', 'class' => 'bg-light text-dark border'];
    }
    if ((int) $onServer === 1) {
        return ['label' => 'En biblioteca', 'class' => 'bg-success'];
    }
    return ['label' => 'No está en el servidor', 'class' => 'bg-danger'];
};

ob_start();
?>
<div class="media-users-page">
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0 text-truncate">Usuarios Media</h4>
    <div class="d-flex gap-2 flex-wrap media-users-toolbar">
        <form method="POST" action="/media-users/sync-membership" class="d-inline">
            <?= csrf_field() ?>
            <?php if ($currentServerId): ?>
            <input type="hidden" name="server_id" value="<?= (int) $currentServerId ?>">
            <?php endif; ?>
            <?php if ($currentStatus): ?>
            <input type="hidden" name="status" value="<?= e($currentStatus) ?>">
            <?php endif; ?>
            <?php if ($currentOnServer !== null): ?>
            <input type="hidden" name="on_server" value="<?= $currentOnServer ? '1' : '0' ?>">
            <?php endif; ?>
            <?php if ($emptyFilters !== []): ?>
            <input type="hidden" name="filter_empty" value="<?= e(implode(',', $emptyFilters)) ?>">
            <?php endif; ?>
            <?php if ($currentSort): ?>
            <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
            <input type="hidden" name="dir" value="<?= e($currentDir) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-outline-primary btn-sm" title="Reconsulta Plex/Jellyfin y marca quién sigue en la biblioteca">
                <i class="bi bi-arrow-repeat me-1"></i><span class="d-none d-xl-inline">Forzar sincronización</span><span class="d-xl-none">Sync</span>
            </button>
        </form>
        <a href="/media-users/revisar?filter_empty=expires,telegram" class="btn btn-outline-primary btn-sm" title="Revisar uno a uno usuarios sin fecha/Telegram">
            <i class="bi bi-list-check me-1"></i><span class="d-none d-lg-inline">Revisar</span>
        </a>
        <a href="/media-users/activity" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i><span class="d-none d-lg-inline">Actividad</span></a>
        <a href="/media-users/expiring" class="btn btn-outline-warning btn-sm"><i class="bi bi-hourglass-split me-1"></i><span class="d-none d-lg-inline">Próximos vencimientos</span><span class="d-lg-none">Vencen</span></a>
        <a href="/media-users/broadcast" class="btn btn-outline-info btn-sm"><i class="bi bi-megaphone me-1"></i><span class="d-none d-lg-inline">Mensaje masivo</span></a>
        <a href="/media-users/bulk" class="btn btn-outline-primary btn-sm"><i class="bi bi-envelope-plus me-1"></i><span class="d-none d-lg-inline">Añadir emails</span><span class="d-lg-none">Emails</span></a>
        <a href="/media-users/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuevo</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3 media-users-filters">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 gap-md-3 align-items-center">
            <div class="btn-group btn-group-sm flex-wrap">
                <a href="/media-users<?= e($withFilters(['status' => null, 'on_server' => null])) ?>" class="btn btn-outline-secondary <?= !$currentStatus && $currentOnServer === null ? 'active' : '' ?>">Todos</a>
                <a href="/media-users<?= e($withFilters(['status' => 'active', 'on_server' => null])) ?>" class="btn btn-outline-success <?= $currentStatus === 'active' ? 'active' : '' ?>">Activos</a>
                <a href="/media-users<?= e($withFilters(['status' => 'suspended', 'on_server' => null])) ?>" class="btn btn-outline-warning <?= $currentStatus === 'suspended' ? 'active' : '' ?>">Suspendidos</a>
                <a href="/media-users<?= e($withFilters(['status' => 'pending', 'on_server' => null])) ?>" class="btn btn-outline-secondary <?= $currentStatus === 'pending' ? 'active' : '' ?>">Pendientes</a>
                <a href="/media-users<?= e($withFilters(['status' => null, 'on_server' => false])) ?>" class="btn btn-outline-danger <?= $currentOnServer === false ? 'active' : '' ?>">Fuera del servidor</a>
            </div>
            <form method="GET" action="/media-users" class="d-flex gap-2 align-items-center ms-lg-auto flex-wrap flex-grow-1 media-users-search-form">
                <div class="position-relative media-users-search-field flex-grow-1">
                    <input type="search" id="userSearch" class="form-control form-control-sm" placeholder="Buscar usuario, email, Telegram…" autocomplete="off">
                    <div id="userSearchMeta" class="small text-muted mt-1 d-none"></div>
                </div>
                <?php if ($currentStatus): ?>
                <input type="hidden" name="status" value="<?= e($currentStatus) ?>">
                <?php endif; ?>
                <?php if ($currentOnServer !== null): ?>
                <input type="hidden" name="on_server" value="<?= $currentOnServer ? '1' : '0' ?>">
                <?php endif; ?>
                <?php if ($currentSort): ?>
                <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
                <input type="hidden" name="dir" value="<?= e($currentDir) ?>">
                <?php endif; ?>
                <?php if ($emptyFilters !== []): ?>
                <input type="hidden" name="filter_empty" value="<?= e(implode(',', $emptyFilters)) ?>">
                <?php endif; ?>
                <label class="small text-muted mb-0 flex-shrink-0">Servidor:</label>
                <select name="server_id" class="form-select form-select-sm media-users-server-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>">
                        <?= e($server->name) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-2 pt-2 border-top media-users-empty-filters">
            <span class="small text-muted me-1">Campos vacíos:</span>
            <a href="<?= e($toggleEmpty('expires')) ?>"
               class="btn btn-sm <?= in_array('expires', $emptyFilters, true) ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                Sin fecha de caducidad
            </a>
            <a href="<?= e($toggleEmpty('telegram')) ?>"
               class="btn btn-sm <?= in_array('telegram', $emptyFilters, true) ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                Sin Telegram
            </a>
            <a href="<?= e($toggleEmpty('email')) ?>"
               class="btn btn-sm <?= in_array('email', $emptyFilters, true) ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                Sin email
            </a>
            <?php if ($emptyFilters !== []): ?>
            <a href="/media-users<?= e($withFilters(['empty' => []])) ?>" class="btn btn-sm btn-link text-decoration-none">Quitar filtros vacíos</a>
            <?php endif; ?>
            <?php
            $reviewParams = [];
            if ($emptyFilters !== []) {
                $reviewParams['filter_empty'] = implode(',', $emptyFilters);
            }
            if ($currentOnServer !== null) {
                $reviewParams['on_server'] = $currentOnServer ? '1' : '0';
            }
            if ($currentServerId) {
                $reviewParams['server_id'] = (int) $currentServerId;
            }
            $reviewQs = $reviewParams !== [] ? '?' . http_build_query($reviewParams) : '?filter_empty=expires,telegram';
            ?>
            <a href="/media-users/revisar<?= e($reviewQs) ?>" class="btn btn-sm btn-outline-primary ms-auto">
                <i class="bi bi-list-check me-1"></i>Revisar uno a uno
            </a>
        </div>
        <?php if ($currentOnServer === false): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-2 pt-2 border-top">
            <form method="POST" action="/media-users/soft-delete-off-server" class="d-inline"
                  onsubmit="return confirm('¿Eliminar del panel TODOS los usuarios marcados fuera del servidor<?= $currentServerId ? ' de este servidor' : '' ?>? No toca Plex/Jellyfin.');">
                <?= csrf_field() ?>
                <?php if ($currentServerId): ?>
                <input type="hidden" name="server_id" value="<?= (int) $currentServerId ?>">
                <?php endif; ?>
                <?php if ($currentStatus): ?>
                <input type="hidden" name="status" value="<?= e($currentStatus) ?>">
                <?php endif; ?>
                <input type="hidden" name="on_server" value="0">
                <?php if ($emptyFilters !== []): ?>
                <input type="hidden" name="filter_empty" value="<?= e(implode(',', $emptyFilters)) ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="bi bi-trash me-1"></i>Eliminar del panel (todos fuera del servidor)
                </button>
            </form>
            <span class="small text-muted">Solo panel · no borra cuentas en Plex/Jellyfin</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm media-users-card">
    <div class="px-3 py-2 border-bottom bg-light small d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span id="usersCountSummary">
            Mostrando <strong><?= (int) $showingCount ?></strong> de <strong><?= (int) $totalCount ?></strong> usuarios
            <?php if ($totalCount > $perPage): ?>
            <span class="text-muted">(página <?= (int) $page ?>)</span>
            <?php endif; ?>
        </span>
        <span class="text-muted d-none d-md-inline">Clic en cabeceras para ordenar · ID = identificador interno</span>
    </div>
    <div class="table-responsive media-users-table-wrap">
        <table class="table table-hover mb-0 align-middle media-users-table">
            <thead class="table-light">
                <tr>
                    <th class="media-users-col-id">
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('id')) ?>">ID<?= $sortIcon('id') ?></a>
                    </th>
                    <th>
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('username')) ?>">Usuario<?= $sortIcon('username') ?></a>
                    </th>
                    <th class="d-none d-md-table-cell">
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('email')) ?>">Email<?= $sortIcon('email') ?></a>
                    </th>
                    <th class="d-none d-xl-table-cell">
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('server')) ?>">Servidor<?= $sortIcon('server') ?></a>
                    </th>
                    <th>
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('status')) ?>">Estado<?= $sortIcon('status') ?></a>
                    </th>
                    <th class="d-none d-xl-table-cell" title="Biblioteca">Bibl.</th>
                    <th class="d-none d-xl-table-cell" title="Streams máximos">
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('max_streams')) ?>">Str.<?= $sortIcon('max_streams') ?></a>
                    </th>
                    <th title="Fecha de expiración">
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('expires')) ?>">Expira<?= $sortIcon('expires') ?></a>
                    </th>
                    <th title="Vence en">Vence</th>
                    <th title="Telegram Chat ID">
                        <a class="media-users-sort-link text-decoration-none text-body" href="<?= e($sortUrl('telegram')) ?>">Telegram<?= $sortIcon('telegram') ?></a>
                    </th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <?php if (empty($users)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No hay usuarios</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <?php
                    $mb = $membershipBadge($u->on_server ?? null);
                    $tg = normalize_telegram_chat_id($u->telegram_chat_id ?? null);
                    $streams = ($u->max_streams !== null && $u->max_streams !== '')
                        ? max(1, min(50, (int) $u->max_streams))
                        : $defaultMaxStreams;
                ?>
                <tr>
                    <td class="small text-muted media-users-col-id"><?= (int) $u->id ?></td>
                    <td class="min-w-0">
                        <a href="/media-users/<?= e($u->uuid) ?>" class="fw-medium text-decoration-none text-truncate d-inline-block media-users-name">
                            <?= e($u->display_name ?? $u->username) ?>
                        </a>
                        <div class="small text-muted d-md-none text-truncate media-users-name"><?= e($u->email ?? '-') ?></div>
                    </td>
                    <td class="small d-none d-md-table-cell text-truncate media-users-email"><?= e($u->email ?? '-') ?></td>
                    <td class="small d-none d-xl-table-cell">
                        <?= media_service_badge($u->server_type ?? null) ?>
                        <?php if ($u->server_name): ?>
                        <span class="badge bg-light text-dark border text-truncate d-inline-block media-users-server-badge"><?= e($u->server_name) ?></span>
                        <?php elseif (!($u->server_type ?? null)): ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown">
                            <button type="button"
                                    class="badge <?= e($statusBadgeClass((string) $u->status)) ?> border-0 dropdown-toggle media-users-status-toggle"
                                    data-bs-toggle="dropdown" aria-expanded="false"
                                    title="Actualizar / renovar o cambiar estado">
                                <?= e($statusLabel((string) $u->status)) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-start shadow-sm">
                                <li><h6 class="dropdown-header">Actualizar / renovar</h6></li>
                                <?php foreach ([7, 15, 30, 90, 365] as $d): ?>
                                <li>
                                    <button type="button" class="dropdown-item btn-quick-renew"
                                            data-uuid="<?= e($u->uuid) ?>" data-days="<?= (int) $d ?>">
                                        +<?= (int) $d ?> días
                                    </button>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item"
                                            onclick="focusExpiresInput('<?= e($u->uuid) ?>')">
                                        Cambiar fecha…
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if ($u->status === 'active'): ?>
                                <li>
                                    <button type="button" class="dropdown-item text-warning"
                                            onclick="suspendUser('<?= e($u->uuid) ?>')">
                                        Pausar acceso
                                    </button>
                                </li>
                                <?php else: ?>
                                <li>
                                    <button type="button" class="dropdown-item text-success"
                                            onclick="activateUser('<?= e($u->uuid) ?>')">
                                        Activar acceso
                                    </button>
                                </li>
                                <?php endif; ?>
                                <li>
                                    <a class="dropdown-item" href="/media-users/<?= e($u->uuid) ?>">Abrir ficha</a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="/media-users/<?= e($u->uuid) ?>/messages">Mensajes</a>
                                </li>
                            </ul>
                        </div>
                    </td>
                    <td class="d-none d-xl-table-cell">
                        <span class="badge text-truncate d-inline-block media-users-membership-badge <?= e($mb['class']) ?>" title="<?= e($mb['label']) ?><?= e($u->membership_synced_at ? ' · Última sync: ' . $u->membership_synced_at : ' · Aún no se ha forzado sync') ?>">
                            <?= e($mb['label']) ?>
                        </span>
                    </td>
                    <td class="d-none d-xl-table-cell small"><?= (int) $streams ?></td>
                    <td class="small">
                        <input type="date" class="form-control form-control-sm expires-input media-users-expires-input" data-uuid="<?= e($u->uuid) ?>"
                               value="<?= e($u->expires_at ? substr((string) $u->expires_at, 0, 10) : '') ?>">
                    </td>
                    <td class="small text-nowrap">
                        <?php $dl = days_left_badge($u->expires_at); ?>
                        <span class="badge <?= e($dl['class']) ?>"><?= e($dl['label']) ?></span>
                    </td>
                    <td class="small">
                        <input type="text" class="form-control form-control-sm telegram-input media-users-telegram-input" data-uuid="<?= e($u->uuid) ?>"
                               value="<?= e($tg) ?>" placeholder="Chat ID" title="Telegram Chat ID para enviar mensajes">
                        <?php if ($tg !== ''): ?>
                        <div class="small text-success mt-1">Vinculado</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/media-users/<?= e($u->uuid) ?>" class="btn btn-outline-secondary" title="Abrir ficha"><i class="bi bi-person"></i></a>
                            <a href="/media-users/<?= e($u->uuid) ?>/messages" class="btn btn-outline-info" title="Historial mensajes"><i class="bi bi-chat-dots"></i></a>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary dropdown-toggle"
                                        data-bs-toggle="dropdown" aria-expanded="false" title="Actualizar / renovar">
                                    <i class="bi bi-calendar-plus"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <li><h6 class="dropdown-header">Sumar días</h6></li>
                                    <?php foreach ([7, 15, 30, 90, 365] as $d): ?>
                                    <li>
                                        <button type="button" class="dropdown-item btn-quick-renew"
                                                data-uuid="<?= e($u->uuid) ?>" data-days="<?= (int) $d ?>">
                                            +<?= (int) $d ?> días
                                        </button>
                                    </li>
                                    <?php endforeach; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button type="button" class="dropdown-item"
                                                onclick="focusExpiresInput('<?= e($u->uuid) ?>')">
                                            Cambiar fecha…
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <?php if ($u->status === 'active'): ?>
                            <button class="btn btn-outline-warning" onclick="suspendUser('<?= e($u->uuid) ?>')" title="Pausar"><i class="bi bi-pause"></i></button>
                            <?php else: ?>
                            <button class="btn btn-outline-success" onclick="activateUser('<?= e($u->uuid) ?>')" title="Activar"><i class="bi bi-play"></i></button>
                            <?php endif; ?>
                            <?php if (isset($u->on_server) && (int) $u->on_server === 0): ?>
                            <form method="POST" action="/media-users/<?= e($u->uuid) ?>" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar del panel? No toca Plex/Jellyfin.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="btn btn-outline-danger" title="Eliminar del panel"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php
$totalPages = max(1, (int) ($totalPages ?? 1));
$page = max(1, (int) ($page ?? 1));
if ($totalPages > 1):
    $pageQuery = static function (int $p) use ($withFilters): string {
        $params = $withFilters();
        $sep = $params === '' ? '?' : '&';
        return '/media-users' . $params . $sep . 'page=' . $p;
    };
?>
<nav class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3" aria-label="Paginación usuarios">
    <span class="small text-muted">Página <?= (int) $page ?> de <?= (int) $totalPages ?></span>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $page <= 1 ? '#' : e($pageQuery($page - 1)) ?>">Anterior</a>
        </li>
        <?php
        $window = 2;
        $start = max(1, $page - $window);
        $end = min($totalPages, $page + $window);
        if ($start > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= e($pageQuery(1)) ?>">1</a></li>
        <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
        endif;
        for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= e($pageQuery($p)) ?>"><?= $p ?></a>
        </li>
        <?php endfor;
        if ($end < $totalPages):
            if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <li class="page-item"><a class="page-link" href="<?= e($pageQuery($totalPages)) ?>"><?= (int) $totalPages ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $page >= $totalPages ? '#' : e($pageQuery($page + 1)) ?>">Siguiente</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
async function toggleUserStatus(uuid, action, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    try {
        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
        if (!csrf) {
            alert('No hay token CSRF en la página. Recarga con F5 e inténtalo de nuevo.');
            return;
        }
        const res = await fetch(`/media-users/${uuid}/${action}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Csrf-Token': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ _token: csrf }),
        });
        const data = await res.json().catch(() => ({}));
        // Nunca usar data.error si es boolean (el handler global manda error:true).
        const msg = (typeof data.message === 'string' && data.message)
            || (typeof data.error === 'string' && data.error)
            || (res.ok ? 'Hecho.' : 'No se pudo completar la acción (HTTP ' + res.status + ').');
        alert(msg);
        location.reload();
    } catch (err) {
        alert('Error de red: ' + err.message);
    }
}
function suspendUser(uuid) {
    toggleUserStatus(uuid, 'suspend', '¿Suspender este usuario? Se cortará el acceso a la biblioteca.');
}
function activateUser(uuid) {
    toggleUserStatus(uuid, 'activate');
}
function focusExpiresInput(uuid) {
    const input = document.querySelector(`.expires-input[data-uuid="${uuid}"]`);
    if (!input) return;
    input.focus();
    input.showPicker?.();
}
async function renewUserDays(uuid, days) {
    days = Number(days);
    if (!uuid || !days) return;
    if (!confirm(`¿Sumar ${days} días a este usuario?`)) return;
    try {
        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
        const res = await fetch(`/media-users/${uuid}/add-days`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Csrf-Token': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ _token: csrf, days }),
        });
        const data = await res.json().catch(() => ({}));
        const msg = (typeof data.message === 'string' && data.message)
            || (typeof data.error === 'string' && data.error)
            || (res.ok ? 'Días añadidos.' : 'No se pudo renovar (HTTP ' + res.status + ').');
        alert(msg);
        if (res.ok) location.reload();
    } catch (err) {
        alert('Error de red: ' + err.message);
    }
}
document.addEventListener('click', (e) => {
    const btn = e.target.closest?.('.btn-quick-renew');
    if (!btn) return;
    e.preventDefault();
    renewUserDays(btn.dataset.uuid, btn.dataset.days);
});
</script>
JS;
$scripts .= '<script src="' . e(asset('js/media-users-search.js')) . '"></script>';
include base_path('resources/views/layouts/app.php');
