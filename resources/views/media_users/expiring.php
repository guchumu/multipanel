<?php
$buckets = [
    'expired' => [],
    'd3' => [],
    'd7' => [],
    'd30' => [],
];
foreach ($users as $u) {
    $days = isset($u->days_left) ? (int) $u->days_left : (int) (days_left($u->expires_at) ?? 0);
    if ($days < 0) {
        $buckets['expired'][] = $u;
    } elseif ($days <= 3) {
        $buckets['d3'][] = $u;
    } elseif ($days <= 7) {
        $buckets['d7'][] = $u;
    } else {
        $buckets['d30'][] = $u;
    }
}

$bucketMeta = [
    'expired' => ['title' => 'Caducados', 'hint' => 'Ya vencidos', 'icon' => 'bi-exclamation-octagon', 'class' => 'urgency-card--expired'],
    'd3' => ['title' => '3 días', 'hint' => 'Hoy a 3 días', 'icon' => 'bi-hourglass-split', 'class' => 'urgency-card--soon'],
    'd7' => ['title' => '7 días', 'hint' => '4 a 7 días', 'icon' => 'bi-calendar-week', 'class' => 'urgency-card--week'],
    'd30' => ['title' => '30 días', 'hint' => '8 a 30 días', 'icon' => 'bi-calendar3', 'class' => 'urgency-card--month'],
];

$activeBucket = 'expired';
foreach (['expired', 'd3', 'd7', 'd30'] as $key) {
    if ($buckets[$key] !== []) {
        $activeBucket = $key;
        break;
    }
}

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="min-w-0">
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1 text-truncate">Vencimientos</h4>
        <p class="text-muted small mb-0">Por urgencia. Selecciona una tarjeta y actúa sobre esa lista.</p>
    </div>
    <a href="/media-users/broadcast" class="btn btn-outline-info btn-sm flex-shrink-0"><i class="bi bi-megaphone me-1"></i>Mensaje masivo</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/media-users/expiring" class="d-flex flex-wrap gap-2 gap-md-3 align-items-center">
            <label class="small text-muted mb-0">Servidor:</label>
            <select name="server_id" class="form-select form-select-sm" style="min-width: 140px; max-width: 220px;" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($servers as $server): ?>
                <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>><?= e($server->name) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="days" value="30">
            <span class="small text-muted ms-md-auto">
                Total: <strong><?= count($users) ?></strong>
            </span>
        </form>
    </div>
</div>

<?php
$reengageStats = $reengageStats ?? ['contacted' => 0, 'came_back' => 0, 'rate' => 0];
$reengageCfg = $reengageCfg ?? ['trial_days' => 3, 'interval_days' => 14, 'enabled' => true, 'min_expired_days' => 60, 'discount_percent' => 15];
$trialDays = (int) ($reengageCfg['trial_days'] ?? 3);
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="min-w-0">
            <strong class="d-block">Gancho para volver</strong>
            <p class="small text-muted mb-0">
                Antes: renovar a los 15/30/45 días (precio de Facturación).
                Reenganche desde el día <?= (int) ($reengageCfg['min_expired_days'] ?? 60) ?>:
                enlace de pago con <?= (int) ($reengageCfg['discount_percent'] ?? 15) ?>% único o prueba <?= $trialDays ?>d manual.
                Se repiten cada <?= (int) ($reengageCfg['interval_days'] ?? 14) ?> días si no vuelven
                <?= !empty($reengageCfg['enabled']) ? '' : ' (automático ahora apagado)' ?>.
                <a href="/settings/notifications#reengage">Editar mensajes</a>
            </p>
        </div>
        <div class="text-nowrap small">
            <?php if ((int) $reengageStats['contacted'] > 0): ?>
            <span class="badge bg-success-subtle text-success border"><?= (int) $reengageStats['came_back'] ?> volvieron</span>
            <span class="text-muted">de <?= (int) $reengageStats['contacted'] ?> (<?= (int) $reengageStats['rate'] ?>%)</span>
            <?php else: ?>
            <span class="text-muted">Aún no hay envíos de reenganche</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-3" id="urgencyCards">
    <?php foreach ($bucketMeta as $id => $meta): ?>
    <?php $count = count($buckets[$id]); ?>
    <div class="col-6 col-lg-3">
        <button type="button"
                class="urgency-card <?= e($meta['class']) ?> <?= $activeBucket === $id ? 'is-active' : '' ?>"
                data-bucket="<?= e($id) ?>"
                <?= $count === 0 ? 'disabled' : '' ?>>
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="urgency-card-title"><?= e($meta['title']) ?></div>
                    <div class="urgency-card-hint"><?= e($meta['hint']) ?></div>
                </div>
                <i class="bi <?= e($meta['icon']) ?> opacity-75"></i>
            </div>
            <div class="urgency-card-count"><?= $count ?></div>
        </button>
    </div>
    <?php endforeach; ?>
</div>

<div id="bulkMessageBar" class="card border-0 shadow-sm mb-3 d-none">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <strong class="small"><span id="bulkSelectedCount">0</span> seleccionado(s)</strong>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="input-group input-group-sm" style="width: auto; max-width: 11rem;">
                    <span class="input-group-text">+días</span>
                    <input type="number" id="bulkRenewDays" class="form-control" value="30" min="1" max="3650" style="width: 4.5rem;">
                    <button type="button" class="btn btn-success" id="bulkRenewBtn" title="Renovar selección">
                        <i class="bi bi-calendar-plus"></i><span class="d-none d-sm-inline ms-1">Renovar</span>
                    </button>
                </div>
                <button type="button" class="btn btn-warning btn-sm" id="bulkSuspendBtn" title="Suspender selección">
                    <i class="bi bi-pause"></i><span class="d-none d-sm-inline ms-1">Suspender</span>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="bulkReengageBtn" title="Invitar a volver">
                    <i class="bi bi-heart"></i><span class="d-none d-sm-inline ms-1">Invitar a volver</span>
                </button>
                <button type="button" class="btn btn-link btn-sm p-0" id="bulkClearSelection">Limpiar</button>
            </div>
        </div>
        <form method="POST" action="/media-users/expiring/broadcast" id="bulkMessageForm">
            <?= csrf_field() ?>
            <input type="hidden" name="days" value="30">
            <?php if ($currentServerId): ?>
            <input type="hidden" name="server_id" value="<?= (int) $currentServerId ?>">
            <?php endif; ?>
            <div id="bulkUuidInputs"></div>
            <p class="small text-muted mb-2 mb-md-1"><i class="bi bi-telegram me-1"></i>Avisar por Telegram a la selección</p>
            <div class="row g-2">
                <div class="col-12 col-md-3">
                    <input type="text" name="title" class="form-control form-control-sm" value="Aviso de vencimiento" required placeholder="Título">
                </div>
                <div class="col-12 col-md-7">
                    <textarea name="body" class="form-control form-control-sm" rows="2" required placeholder="Hola {display_name}, tu acceso vence el {end_date}…">{variables: {username}, {email}, {display_name}, {end_date}, {days_left}, {server_name}</textarea>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-info btn-sm" onclick="return confirm('¿Enviar Telegram a los usuarios seleccionados?')">
                        <i class="bi bi-send me-1"></i>Avisar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (empty($users)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
        No hay vencimientos en los próximos 30 días
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm expiring-card" data-expiring-section="active">
    <div class="px-3 py-2 border-bottom bg-light small d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span id="expiringListTitle"><?= e($bucketMeta[$activeBucket]['title']) ?></span>
        <label class="mb-0 d-inline-flex align-items-center gap-1">
            <input type="checkbox" class="form-check-input m-0 expiring-select-all" data-section="active">
            <span>Seleccionar visibles</span>
        </label>
    </div>
    <div class="table-responsive expiring-table-wrap">
        <table class="table table-hover mb-0 align-middle expiring-table expiring-table-compact">
            <thead class="table-light">
                <tr>
                    <th style="width: 2.5rem;"></th>
                    <th>Usuario</th>
                    <th>Servidor</th>
                    <th>Días</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <?php
                $days = isset($u->days_left) ? (int) $u->days_left : (int) (days_left($u->expires_at) ?? 0);
                if ($days < 0) {
                    $bucket = 'expired';
                } elseif ($days <= 3) {
                    $bucket = 'd3';
                } elseif ($days <= 7) {
                    $bucket = 'd7';
                } else {
                    $bucket = 'd30';
                }
                $dl = days_left_badge($u->expires_at);
                $hidden = $bucket !== $activeBucket ? ' d-none' : '';
                ?>
                <tr class="expiring-row<?= $hidden ?>" data-bucket="<?= e($bucket) ?>">
                    <td>
                        <input type="checkbox" class="form-check-input expiring-select"
                               value="<?= e($u->uuid) ?>"
                               data-has-telegram="<?= $u->telegram_chat_id ? '1' : '0' ?>"
                               aria-label="Seleccionar <?= e($u->display_name ?? $u->username) ?>">
                    </td>
                    <td class="min-w-0">
                        <a href="/media-users/<?= e($u->uuid) ?>" class="fw-medium text-decoration-none text-truncate d-inline-block" style="max-width: 14rem;">
                            <?= e($u->display_name ?? $u->username) ?>
                        </a>
                        <?php if (!empty($u->email)): ?>
                        <div class="small text-muted text-truncate" style="max-width: 14rem;"><?= e($u->email) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($u->server_name): ?>
                        <span class="badge bg-light text-dark border text-truncate d-inline-block" style="max-width: 9rem;"><?= e($u->server_name) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($dl['class']) ?>"><?= e($dl['label']) ?></span></td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Acciones">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($bucket === 'expired'): ?>
                                <li>
                                    <a class="dropdown-item btn-reengage-invite" href="#" data-uuid="<?= e($u->uuid) ?>">
                                        <i class="bi bi-heart me-2"></i>Invitar a volver
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item btn-reengage-trial" href="#" data-uuid="<?= e($u->uuid) ?>" data-days="<?= $trialDays ?>">
                                        <i class="bi bi-gift me-2"></i>Abrir prueba <?= $trialDays ?>d
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="/media-users/<?= e($u->uuid) ?>"><i class="bi bi-eye me-2"></i>Ver ficha</a></li>
                                <li><a class="dropdown-item" href="/media-users/<?= e($u->uuid) ?>#stripe"><i class="bi bi-credit-card me-2"></i>Enlace de pago</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php foreach ([7, 15, 30, 90, 365] as $opt): ?>
                                <li>
                                    <a class="dropdown-item btn-quick-renew" href="#" data-uuid="<?= e($u->uuid) ?>" data-days="<?= $opt ?>">
                                        <i class="bi bi-calendar-plus me-2"></i>+<?= $opt ?> días
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <?php if ($u->telegram_chat_id): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><span class="dropdown-item-text small text-muted"><i class="bi bi-telegram me-2"></i>Tiene Telegram</span></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$scripts = '<script>window.EXPIRING_FILTER_DAYS = 30;'
    . 'window.EXPIRING_SERVER_ID = ' . json_encode($currentServerId ? (int) $currentServerId : null) . ';'
    . 'window.EXPIRING_BUCKET_TITLES = ' . json_encode(array_map(static fn ($m) => $m['title'], $bucketMeta), JSON_UNESCAPED_UNICODE) . ';</script>';
$scripts .= '<script src="' . e(asset('js/media-users-expiring.js')) . '?v=' . (@filemtime(public_path('assets/js/media-users-expiring.js')) ?: '1') . '"></script>';
include base_path('resources/views/layouts/app.php');
