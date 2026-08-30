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
                    · Expira: <?= e(expires_date_display($mediaUser->expires_at)) ?>
                    · Estado: <?= e($mediaUser->status) ?>
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
            $waValue = trim((string) ($mediaUser->metaGet('whatsapp_phone') ?? ''));
            $expiresValue = expires_date_input($mediaUser->expires_at);
            $statusValue = (string) ($mediaUser->status ?? 'pending');
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

            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="review-server">Servidor</label>
                    <select class="form-select form-select-sm" id="review-server" name="user_server_id">
                        <option value="">Sin servidor</option>
                        <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server->id ?>" <?= (int) ($mediaUser->server_id ?? 0) === (int) $server->id ? 'selected' : '' ?>>
                            <?= e($server->name) ?> (<?= e($server->type) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="review-status">Estado</label>
                    <select class="form-select form-select-sm" id="review-status" name="status">
                        <?php foreach (['active' => 'Activo', 'suspended' => 'Suspendido', 'expired' => 'Caducado', 'pending' => 'Pendiente', 'invited' => 'Invitado'] as $st => $label): ?>
                        <option value="<?= e($st) ?>" <?= $statusValue === $st ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="review-expires">Fecha expiración</label>
                    <input type="date" class="form-control form-control-sm" id="review-expires" name="expires_at"
                           value="<?= e($expiresValue) ?>">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1 mb-3">
                <?php foreach ([7, 15, 30, 90, 365] as $days): ?>
                <button type="button" class="btn btn-sm btn-outline-primary review-add-days" data-days="<?= $days ?>">+<?= $days ?>d</button>
                <?php endforeach; ?>
            </div>

            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="review-email">Email</label>
                    <input type="email" class="form-control form-control-sm" id="review-email" name="email"
                           value="<?= e($emailValue) ?>" placeholder="cliente@email.com" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="review-telegram">Telegram Chat ID</label>
                    <input type="text" class="form-control form-control-sm" id="review-telegram" name="telegram_chat_id"
                           value="<?= e($tgValue) ?>" placeholder="Ej. 123456789" autocomplete="off" inputmode="numeric">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="review-whatsapp">WhatsApp</label>
                    <input type="text" class="form-control form-control-sm" id="review-whatsapp" name="whatsapp_phone"
                           value="<?= e($waValue) ?>" placeholder="346xxxxxxxx" autocomplete="off">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small mb-1" for="review-notes">Notas privadas</label>
                <textarea class="form-control form-control-sm" id="review-notes" name="notes" rows="2"
                          placeholder="Identificación, incidencias…"><?= e($mediaUser->notes ?? '') ?></textarea>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="submit" name="action" value="save_all" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Guardar cambios
                </button>
                <button type="submit" name="action" value="lookup" class="btn btn-outline-info"
                        title="Busca email/usuario en Plex/Jellyfin, clientes o registros previos del panel">
                    <i class="bi bi-search me-1"></i>Buscar email / usuario
                </button>
            </div>
            <p class="small text-muted mb-3">Usuario Plex/Jellyfin: <strong><?= e($mediaUser->username ?: '—') ?></strong>
                <?php if (trim((string) ($mediaUser->external_id ?? '')) !== ''): ?>
                · ID servidor: <?= e($mediaUser->external_id) ?>
                <?php endif; ?>
            </p>

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
        <script>
        document.querySelectorAll('.review-add-days').forEach((btn) => {
            btn.addEventListener('click', () => {
                const input = document.getElementById('review-expires');
                if (!input) return;
                const days = Number(btn.dataset.days || 0);
                if (!days) return;
                const baseStr = input.value || new Date().toISOString().slice(0, 10);
                const parts = baseStr.split('-').map(Number);
                const dt = new Date(parts[0], parts[1] - 1, parts[2]);
                dt.setDate(dt.getDate() + days);
                input.value = [
                    dt.getFullYear(),
                    String(dt.getMonth() + 1).padStart(2, '0'),
                    String(dt.getDate()).padStart(2, '0'),
                ].join('-');
            });
        });
        </script>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include base_path('resources/views/layouts/app.php');
