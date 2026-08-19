<?php
$membershipBadge = static function ($onServer): array {
    if ($onServer === null || $onServer === '') {
        return ['label' => 'Sin sync', 'class' => 'bg-light text-dark border', 'hint' => 'Pulsa Comprobar biblioteca.'];
    }
    if ((int) $onServer === 1) {
        return ['label' => 'En biblioteca', 'class' => 'bg-success', 'hint' => 'Sigue en la lista del servidor.'];
    }
    return ['label' => 'No está en el servidor', 'class' => 'bg-danger', 'hint' => 'Puedes eliminarlo del panel con seguridad.'];
};
$emptyFilters = is_array($emptyFilters ?? null) ? $emptyFilters : [];
$currentOnServer = $currentOnServer ?? null;
$currentServerId = $currentServerId ?? null;
$mediaUser = $mediaUser ?? null;
$remaining = (int) ($remaining ?? 0);

$queryParams = [];
if ($emptyFilters !== []) {
    $queryParams['filter_empty'] = implode(',', $emptyFilters);
}
if ($currentOnServer !== null) {
    $queryParams['on_server'] = $currentOnServer ? '1' : '0';
}
if ($currentServerId) {
    $queryParams['server_id'] = (int) $currentServerId;
}
$qs = $queryParams !== [] ? '?' . http_build_query($queryParams) : '';

$toggleEmpty = static function (string $key) use ($emptyFilters, $currentOnServer, $currentServerId): string {
    $next = $emptyFilters;
    if (in_array($key, $next, true)) {
        $next = array_values(array_filter($next, static fn ($v) => $v !== $key));
    } else {
        $next[] = $key;
    }
    $params = [];
    if ($next !== []) {
        $params['filter_empty'] = implode(',', $next);
    }
    if ($currentOnServer !== null) {
        $params['on_server'] = $currentOnServer ? '1' : '0';
    }
    if ($currentServerId) {
        $params['server_id'] = (int) $currentServerId;
    }
    return '/media-users/revisar' . ($params !== [] ? '?' . http_build_query($params) : '');
};

ob_start();
?>
<div class="mb-3">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver al listado</a>
    <h4 class="mt-2 mb-1">Revisar usuarios sin datos</h4>
    <p class="text-muted small mb-0">
        Comprueba uno a uno si siguen en el servidor. Ideal tras <strong>Forzar sincronización</strong>
        y filtros Sin fecha / Sin Telegram / Fuera del servidor.
    </p>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-muted">Cola:</span>
            <a href="<?= e($toggleEmpty('expires')) ?>"
               class="btn btn-sm <?= in_array('expires', $emptyFilters, true) ? 'btn-secondary' : 'btn-outline-secondary' ?>">Sin fecha</a>
            <a href="<?= e($toggleEmpty('telegram')) ?>"
               class="btn btn-sm <?= in_array('telegram', $emptyFilters, true) ? 'btn-secondary' : 'btn-outline-secondary' ?>">Sin Telegram</a>
            <a href="<?= e($toggleEmpty('email')) ?>"
               class="btn btn-sm <?= in_array('email', $emptyFilters, true) ? 'btn-secondary' : 'btn-outline-secondary' ?>">Sin email</a>
            <?php
            $fueraParams = $queryParams;
            $fueraParams['on_server'] = '0';
            $todosParams = $queryParams;
            unset($todosParams['on_server']);
            ?>
            <a href="/media-users/revisar?<?= e(http_build_query($fueraParams)) ?>"
               class="btn btn-sm <?= $currentOnServer === false ? 'btn-danger' : 'btn-outline-danger' ?>">Fuera del servidor</a>
            <a href="/media-users/revisar?<?= e(http_build_query($todosParams)) ?>"
               class="btn btn-sm <?= $currentOnServer === null ? 'btn-outline-secondary active' : 'btn-outline-secondary' ?>">Cualquier bibl.</a>
            <form method="GET" action="/media-users/revisar" class="d-flex gap-2 align-items-center ms-auto">
                <?php if ($emptyFilters !== []): ?>
                <input type="hidden" name="filter_empty" value="<?= e(implode(',', $emptyFilters)) ?>">
                <?php endif; ?>
                <?php if ($currentOnServer !== null): ?>
                <input type="hidden" name="on_server" value="<?= $currentOnServer ? '1' : '0' ?>">
                <?php endif; ?>
                <select name="server_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width: 10rem;">
                    <option value="">Todos los servidores</option>
                    <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server->id ?>" <?= (int) $currentServerId === (int) $server->id ? 'selected' : '' ?>>
                        <?= e($server->name) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <p class="small text-muted mb-0 mt-2">Pendientes con estos filtros: <strong><?= (int) $remaining ?></strong></p>
    </div>
</div>

<?php if ($mediaUser === null): ?>
<div class="alert alert-success mb-0">
    <i class="bi bi-check-circle me-1"></i>No quedan usuarios en esta cola. Vuelve al
    <a href="/media-users" class="alert-link">listado</a>
    o cambia los filtros.
</div>
<?php else:
    $mb = $membershipBadge($mediaUser->on_server ?? null);
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
            <div>
                <h5 class="mb-1">
                    <a href="/media-users/<?= e($mediaUser->uuid) ?>" class="text-decoration-none">
                        <?= e($mediaUser->display_name ?? $mediaUser->username) ?>
                    </a>
                </h5>
                <p class="text-muted small mb-0">
                    ID <?= (int) $mediaUser->id ?>
                    · <?= e($mediaUser->email ?: 'sin email') ?>
                    · <?= e($mediaUser->server_name ?? 'Sin servidor') ?>
                    · Telegram: <?= e($mediaUser->telegram_chat_id ?: '—') ?>
                    · Expira: <?= e($mediaUser->expires_at ? substr((string) $mediaUser->expires_at, 0, 10) : '—') ?>
                </p>
            </div>
            <div class="text-end">
                <span class="badge <?= e($mb['class']) ?> fs-6"><?= e($mb['label']) ?></span>
                <?php if (!empty($mediaUser->membership_synced_at)): ?>
                <div class="small text-muted mt-1">Sync: <?= e($mediaUser->membership_synced_at) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-<?= (int) ($mediaUser->on_server ?? -1) === 1 ? 'success' : ((int) ($mediaUser->on_server ?? -1) === 0 ? 'danger' : 'secondary') ?> py-2">
            <?= e($mb['hint']) ?>
        </div>

        <?php
            $tgValue = normalize_telegram_chat_id($mediaUser->telegram_chat_id ?? null);
            $emailValue = trim((string) ($mediaUser->email ?? ''));
        ?>
        <form method="POST" action="/media-users/revisar" class="mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="uuid" value="<?= e($mediaUser->uuid) ?>">
            <?php if ($emptyFilters !== []): ?>
            <input type="hidden" name="filter_empty" value="<?= e(implode(',', $emptyFilters)) ?>">
            <?php endif; ?>
            <?php if ($currentOnServer !== null): ?>
            <input type="hidden" name="on_server" value="<?= $currentOnServer ? '1' : '0' ?>">
            <?php endif; ?>
            <?php if ($currentServerId): ?>
            <input type="hidden" name="server_id" value="<?= (int) $currentServerId ?>">
            <?php endif; ?>

            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="review-email">Email</label>
                    <input type="email" class="form-control" id="review-email" name="email"
                           value="<?= e($emailValue) ?>" placeholder="cliente@email.com" autocomplete="off">
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="review-telegram">Telegram Chat ID</label>
                    <input type="text" class="form-control" id="review-telegram" name="telegram_chat_id"
                           value="<?= e($tgValue) ?>" placeholder="Ej. 123456789" autocomplete="off"
                           inputmode="numeric">
                </div>
                <div class="col-md-2">
                    <button type="submit" name="action" value="save_contact" class="btn btn-outline-primary w-100">
                        <i class="bi bi-save me-1"></i>Guardar
                    </button>
                </div>
            </div>
            <p class="small text-muted mb-3 mb-md-2">Rellena los huecos y pulsa Guardar antes de pasar al siguiente.</p>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" name="action" value="sync" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1"></i>Comprobar biblioteca
                </button>
                <button type="submit" name="action" value="next" class="btn btn-outline-success"
                        title="Marca como revisado y pasa al siguiente sin borrar">
                    <i class="bi bi-check2 me-1"></i>Sigue en servidor
                </button>
                <button type="submit" name="action" value="soft_delete" class="btn btn-outline-danger"
                        onclick="return confirm('¿Eliminar del panel? No borra la cuenta en Plex/Jellyfin.')">
                    <i class="bi bi-trash me-1"></i>No está → Eliminar del panel
                </button>
                <button type="submit" name="action" value="next" class="btn btn-outline-secondary">
                    Siguiente <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include base_path('resources/views/layouts/app.php');
