<?php
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
$ticketStatusEs = static function (string $s): string {
    return match ($s) {
        'open' => 'Abierto',
        'in_progress' => 'En curso',
        'waiting' => 'En espera',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
        default => $s,
    };
};
$dateFmt = static function (?string $d): string {
    if ($d === null || $d === '') {
        return '—';
    }
    $ts = strtotime(substr($d, 0, 10));
    if ($ts === false) {
        return e($d);
    }
    return date('d/m/Y', $ts);
};
$canPay = !empty($stripeConfigured) && !empty($renewalPresets);
$firstPreset = $canPay ? $renewalPresets[0] : null;
ob_start();
?>
<section class="portal-hero portal-hero--<?= e($accountStatus['class'] ?? 'secondary') ?>">
    <p class="portal-hero-brand">MultiPanel</p>
    <h1 class="portal-hero-title">Hola, <?= e($portalUser->display_name ?? $portalUser->username) ?></h1>
    <p class="portal-hero-hint"><?= e($accountStatus['hint'] ?? '') ?></p>

    <div class="portal-status-row">
        <span class="portal-status-pill portal-status-pill--<?= e($accountStatus['class'] ?? 'secondary') ?>">
            <?= e($accountStatus['label'] ?? '—') ?>
        </span>
        <?php if (!empty($expiry['date'])): ?>
        <div class="portal-expiry">
            <span class="portal-expiry-label">Caduca</span>
            <span class="portal-expiry-date"><?= $dateFmt($expiry['date']) ?></span>
            <span class="portal-expiry-days"><?= e($expiry['label'] ?? '') ?></span>
        </div>
        <?php else: ?>
        <div class="portal-expiry">
            <span class="portal-expiry-days"><?= e($expiry['label'] ?? '') ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="portal-hero-cta">
        <?php if ($canPay && $firstPreset): ?>
        <form method="POST" action="/portal/payment/renew">
            <?= csrf_field() ?>
            <input type="hidden" name="amount" value="<?= e((string) $firstPreset['price']) ?>">
            <input type="hidden" name="days" value="<?= (int) $firstPreset['days'] ?>">
            <button type="submit" class="btn btn-light btn-lg portal-cta-primary">
                Renovar · <?= e($firstPreset['label']) ?> (<?= number_format((float) $firstPreset['price'], 2) ?> €)
            </button>
        </form>
        <a href="/portal/subscription" class="btn btn-outline-light">Ver todas las opciones</a>
        <?php else: ?>
        <a href="/portal/subscription" class="btn btn-light btn-lg portal-cta-primary">Renovar / pagar</a>
        <?php endif; ?>
    </div>
</section>

<nav class="portal-quick" aria-label="Accesos rápidos">
    <a href="/portal/profile" class="portal-quick-item"><i class="bi bi-person"></i><span>Mi perfil</span></a>
    <a href="/portal/peticiones" class="portal-quick-item"><i class="bi bi-film"></i><span>Peticiones</span></a>
    <a href="/portal/tickets" class="portal-quick-item"><i class="bi bi-life-preserver"></i><span>Soporte</span></a>
    <a href="/portal/subscription" class="portal-quick-item"><i class="bi bi-credit-card"></i><span>Pagar</span></a>
</nav>

<div class="row g-3 mt-1">
    <div class="col-12 col-md-6">
        <div class="card portal-card h-100">
            <div class="card-body">
                <h2 class="portal-section-title">Streams en curso</h2>
                <p class="mb-2">
                    <span class="fs-3 fw-semibold"><?= count($liveStreams ?? []) ?></span>
                    <span class="text-muted"> / <?= (int) ($portalUser->max_streams ?? 0) ?> máx.</span>
                </p>
                <?php if (!empty($liveStreams)): ?>
                <ul class="list-unstyled small mb-0 portal-stream-list">
                    <?php foreach (array_slice($liveStreams, 0, 4) as $s): ?>
                    <li class="text-truncate">· <?= e($s['title'] ?? $s['media_title'] ?? 'Reproducción') ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="small text-muted mb-0">Ninguna reproducción activa ahora.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card portal-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="portal-section-title mb-0">Renovar</h2>
                    <a href="/portal/subscription" class="small">Más opciones</a>
                </div>
                <?php if ($canPay): ?>
                <div class="d-grid gap-2">
                    <?php foreach (array_slice($renewalPresets, 0, 3) as $preset): ?>
                    <form method="POST" action="/portal/payment/renew">
                        <?= csrf_field() ?>
                        <input type="hidden" name="amount" value="<?= e((string) $preset['price']) ?>">
                        <input type="hidden" name="days" value="<?= (int) $preset['days'] ?>">
                        <button type="submit" class="btn btn-primary w-100">
                            <?= e($preset['label']) ?> · <?= number_format((float) $preset['price'], 2) ?> €
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="small text-muted mb-2">El pago online no está disponible ahora. Contacta con soporte o prueba más tarde.</p>
                <a href="/portal/tickets/create" class="btn btn-outline-primary btn-sm">Abrir ticket</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12 col-lg-6">
        <div class="card portal-card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="portal-section-title mb-0">Mis peticiones</h2>
                <a href="/portal/peticiones" class="btn btn-sm btn-outline-primary">Ver / pedir</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($peticiones['configured'])): ?>
                <li class="list-group-item text-muted small"><?= e($peticiones['note'] ?? 'Peticiones no disponibles.') ?></li>
                <?php elseif (empty($peticiones['items'])): ?>
                <li class="list-group-item text-muted small"><?= e($peticiones['note'] ?? 'No tienes peticiones todavía.') ?></li>
                <?php else: ?>
                <?php foreach (array_slice($peticiones['items'], 0, 5) as $p): ?>
                <?php $st = $peticionEstado($p); ?>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span class="text-truncate"><?= e($p['nombrepeticion'] ?? 'Petición') ?></span>
                    <span class="badge text-bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card portal-card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="portal-section-title mb-0">Soporte</h2>
                <a href="/portal/tickets/create" class="btn btn-sm btn-primary">Nuevo</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($tickets)): ?>
                <li class="list-group-item text-muted text-center small">Sin tickets abiertos</li>
                <?php else: ?>
                <?php foreach ($tickets as $t): ?>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <a class="text-truncate" href="/portal/tickets/<?= e($t['uuid']) ?>"><?= e($t['subject']) ?></a>
                    <span class="badge text-bg-secondary"><?= e($ticketStatusEs((string) $t['status'])) ?></span>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
