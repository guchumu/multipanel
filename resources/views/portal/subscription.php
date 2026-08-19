<?php
$dateFmt = static function (?string $d): string {
    if ($d === null || $d === '') {
        return '—';
    }
    $ts = strtotime(substr($d, 0, 10));
    return $ts === false ? e($d) : date('d/m/Y', $ts);
};
$serverInfo = is_array($serverInfo ?? null) ? $serverInfo : ['name' => '—', 'type_label' => '—'];
$canPay = !empty($stripeConfigured) && !empty($renewalPresets);
ob_start();
?>
<h1 class="portal-page-title">Renovar / pagar</h1>
<p class="portal-page-lead">Amplía tu acceso. El cobro es seguro y los días se suman a tu cuenta al completar el pago.</p>

<div class="card portal-card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 small">
            <div class="col-6 col-md-3"><span class="text-muted">Servicio</span><div class="fw-semibold"><?= e($serverInfo['type_label'] ?? '—') ?></div></div>
            <div class="col-6 col-md-3"><span class="text-muted">Servidor</span><div class="fw-semibold text-truncate"><?= e($serverInfo['name'] ?? '—') ?></div></div>
            <div class="col-6 col-md-3"><span class="text-muted">Caduca</span><div class="fw-semibold"><?= !empty($expiry['date']) ? $dateFmt($expiry['date']) : '—' ?></div></div>
            <div class="col-6 col-md-3"><span class="text-muted">Estado</span><div class="fw-semibold"><?= e($expiry['label'] ?? '—') ?></div></div>
        </div>
    </div>
</div>

<?php if ($canPay): ?>
<div class="card portal-card mb-4">
    <div class="card-body">
        <h2 class="portal-section-title">Elige periodo</h2>
        <p class="small text-muted mb-3">Pulsa y te llevamos a la pasarela. Al pagar, se amplía tu caducidad.</p>
        <div class="row g-3">
            <?php foreach ($renewalPresets as $preset): ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <form method="POST" action="/portal/payment/renew" class="h-100">
                    <?= csrf_field() ?>
                    <input type="hidden" name="amount" value="<?= e((string) $preset['price']) ?>">
                    <input type="hidden" name="days" value="<?= (int) $preset['days'] ?>">
                    <button type="submit" class="btn btn-primary w-100 h-100 py-3 portal-preset-btn">
                        <span class="d-block fw-semibold"><?= e($preset['label']) ?></span>
                        <span class="d-block fs-4"><?= number_format((float) $preset['price'], 2) ?> €</span>
                        <span class="d-block small opacity-75"><?= (int) $preset['days'] ?> días</span>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card portal-card mb-4">
    <div class="card-body">
        <p class="mb-2">El pago online no está disponible ahora.</p>
        <a href="/portal/tickets/create" class="btn btn-primary btn-sm">Contactar soporte</a>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($plans)): ?>
<h2 class="portal-section-title text-white mb-3">Planes</h2>
<div class="row g-3">
    <?php foreach ($plans as $plan): ?>
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card portal-card h-100">
            <div class="card-body">
                <h3 class="h5"><?= e($plan['name']) ?></h3>
                <p class="fs-3 text-primary mb-1"><?= number_format((float) $plan['price'], 2) ?> €</p>
                <p class="text-muted small mb-3">/ <?= e($plan['interval']) ?></p>
                <ul class="list-unstyled small mb-3">
                    <li><i class="bi bi-check text-success"></i> <?= (int) $plan['max_streams'] ?> streams</li>
                    <li><i class="bi bi-check text-success"></i> <?= (int) $plan['max_devices'] ?> dispositivos</li>
                </ul>
                <form method="POST" action="/portal/payment/checkout">
                    <?= csrf_field() ?>
                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                    <div class="d-grid gap-2">
                        <button name="gateway" value="stripe" class="btn btn-primary btn-sm">Pagar con tarjeta</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="text-white-50 small mt-4 mb-0"><a class="link-light" href="/portal">← Volver al inicio</a></p>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
