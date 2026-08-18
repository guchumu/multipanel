<?php
$statusLabel = match ((string) ($portalUser->status ?? '')) {
    'active' => 'Activa',
    'suspended' => 'Suspendida',
    'pending' => 'Pendiente',
    'expired' => 'Caducada',
    default => (string) ($portalUser->status ?? '—'),
};
$statusClass = match ((string) ($portalUser->status ?? '')) {
    'active' => 'success',
    'suspended' => 'warning',
    'expired' => 'danger',
    default => 'secondary',
};
$peticionEstado = static function (array $p): array {
    $subido = (string) ($p['subido'] ?? '0');
    $aceptado = (string) ($p['aceptado'] ?? '0');
    $activo = (string) ($p['activo'] ?? '1');
    $motivo = (int) ($p['idmotivo'] ?? 0);
    if ($subido === '1') {
        return ['label' => 'Subida', 'class' => 'success'];
    }
    if ($aceptado === '1') {
        return ['label' => 'En proceso', 'class' => 'info'];
    }
    if ($activo === '0' || $motivo > 0) {
        return ['label' => 'Denegada', 'class' => 'danger'];
    }
    return ['label' => 'Pendiente', 'class' => 'warning'];
};
ob_start();
?>
<div class="portal-home">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h4 class="text-white mb-1">Hola, <?= e($portalUser->display_name ?? $portalUser->username) ?></h4>
            <p class="text-white-50 small mb-0">Resumen de tu cuenta y renovación</p>
        </div>
        <?php if (!empty($stripeConfigured) && !empty($renewalPresets)): ?>
        <form method="POST" action="/portal/payment/renew" class="d-flex flex-wrap gap-2">
            <?= csrf_field() ?>
            <?php $first = $renewalPresets[0]; ?>
            <input type="hidden" name="amount" value="<?= e((string) $first['price']) ?>">
            <input type="hidden" name="days" value="<?= (int) $first['days'] ?>">
            <button type="submit" class="btn btn-light btn-sm fw-semibold">
                <i class="bi bi-credit-card me-1"></i>Renovar · <?= e($first['label']) ?> (<?= number_format((float) $first['price'], 2) ?> €)
            </button>
        </form>
        <?php else: ?>
        <a href="/portal/subscription" class="btn btn-light btn-sm"><i class="bi bi-credit-card me-1"></i>Ver planes</a>
        <?php endif; ?>
    </div>

    <div class="row g-3 g-md-4">
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card portal-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0 text-muted">Estado y caducidad</h6>
                        <i class="bi bi-shield-check text-<?= e($statusClass) ?>"></i>
                    </div>
                    <span class="badge bg-<?= e($statusClass) ?> mb-2"><?= e($statusLabel) ?></span>
                    <?php if (!empty($expiry['date'])): ?>
                    <p class="mb-1 fs-5 fw-semibold"><?= e($expiry['date']) ?></p>
                    <?php endif; ?>
                    <span class="badge text-bg-<?= e($expiry['class'] ?? 'secondary') ?>"><?= e($expiry['label'] ?? '') ?></span>
                    <?php if (!empty($expiry['expired']) || (($expiry['days_left'] ?? 99) <= 7)): ?>
                    <p class="small text-muted mt-3 mb-0">Renueva para mantener el acceso sin interrupciones.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <div class="card portal-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0 text-muted">Streams</h6>
                        <i class="bi bi-play-btn text-info"></i>
                    </div>
                    <p class="mb-1">
                        <span class="fs-4 fw-semibold"><?= count($liveStreams ?? []) ?></span>
                        <span class="text-muted small">en directo</span>
                    </p>
                    <p class="small text-muted mb-2">Límite: <?= (int) $portalUser->max_streams ?> simultáneos</p>
                    <?php if (!empty($liveStreams)): ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach (array_slice($liveStreams, 0, 3) as $s): ?>
                        <li class="text-truncate">· <?= e($s['title'] ?? $s['media_title'] ?? 'Reproducción') ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="small text-muted mb-0">Ninguna reproducción activa ahora.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-12 col-lg-4">
            <div class="card portal-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0 text-muted">Suscripción / pago</h6>
                        <i class="bi bi-credit-card text-primary"></i>
                    </div>
                    <?php if ($subscription): ?>
                    <strong><?= e($subscription['plan_name']) ?></strong>
                    <p class="small text-muted mb-2"><?= number_format((float) $subscription['price'], 2) ?> € / <?= e($subscription['interval']) ?></p>
                    <?php else: ?>
                    <p class="small text-muted mb-2">Sin plan contratado en el portal.</p>
                    <?php endif; ?>

                    <?php if (!empty($stripeConfigured) && !empty($renewalPresets)): ?>
                    <div class="d-grid gap-2">
                        <?php foreach (array_slice($renewalPresets, 0, 3) as $preset): ?>
                        <form method="POST" action="/portal/payment/renew">
                            <?= csrf_field() ?>
                            <input type="hidden" name="amount" value="<?= e((string) $preset['price']) ?>">
                            <input type="hidden" name="days" value="<?= (int) $preset['days'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <?= e($preset['label']) ?> · <?= number_format((float) $preset['price'], 2) ?> €
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <a href="/portal/subscription" class="btn btn-sm btn-outline-primary">Ver planes</a>
                    <p class="small text-muted mt-2 mb-0">Si el cobro no está configurado, contacta con soporte.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 g-md-4 mt-1">
        <div class="col-12 col-lg-4">
            <div class="card portal-card h-100">
                <div class="card-header bg-white"><h6 class="mb-0">Mis peticiones</h6></div>
                <ul class="list-group list-group-flush">
                    <?php if (empty($peticiones['configured'])): ?>
                    <li class="list-group-item text-muted small"><?= e($peticiones['note'] ?? 'Peticiones no disponibles.') ?></li>
                    <?php elseif (!empty($peticiones['note']) && empty($peticiones['items'])): ?>
                    <li class="list-group-item text-muted small"><?= e($peticiones['note']) ?></li>
                    <?php elseif (empty($peticiones['items'])): ?>
                    <li class="list-group-item text-muted text-center small">No tienes peticiones abiertas</li>
                    <?php else: ?>
                    <?php foreach ($peticiones['items'] as $p): ?>
                    <?php $st = $peticionEstado($p); ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span class="text-truncate"><?= e($p['nombrepeticion'] ?? 'Petición') ?></span>
                        <span class="badge bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <div class="card portal-card h-100">
                <div class="card-header bg-white"><h6 class="mb-0">Reproducciones recientes</h6></div>
                <ul class="list-group list-group-flush">
                    <?php if (empty($recentPlays)): ?>
                    <li class="list-group-item text-muted text-center">Sin reproducciones</li>
                    <?php else: ?>
                    <?php foreach ($recentPlays as $play): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span class="text-truncate"><?= e($play['title'] ?? 'Desconocido') ?></span>
                        <small class="text-muted text-nowrap"><?= e(substr((string) ($play['started_at'] ?? ''), 0, 16)) ?></small>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <div class="card portal-card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Tickets soporte</h6>
                    <a href="/portal/tickets/create" class="btn btn-sm btn-primary">Nuevo</a>
                </div>
                <ul class="list-group list-group-flush">
                    <?php if (empty($tickets)): ?>
                    <li class="list-group-item text-muted text-center">Sin tickets</li>
                    <?php else: ?>
                    <?php foreach ($tickets as $t): ?>
                    <li class="list-group-item">
                        <a href="/portal/tickets/<?= e($t['uuid']) ?>"><?= e($t['subject']) ?></a>
                        <span class="badge bg-secondary float-end"><?= e($t['status']) ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
