<?php ob_start(); ?>
<h1 class="portal-page-title">Instrucciones de pago — <?= e(ucfirst((string) $gateway)) ?></h1>
<div class="card portal-card">
    <div class="card-body">
        <h2 class="h5"><?= e($plan['name']) ?> — <?= number_format((float) $plan['price'], 2) ?> <?= e($plan['currency'] ?? 'EUR') ?></h2>

        <?php if ($gateway === 'bizum'): ?>
        <div class="alert alert-info">
            <p class="mb-1"><strong>1.</strong> Abre la app Bizum en tu móvil</p>
            <p class="mb-1"><strong>2.</strong> Envía <strong><?= number_format((float) $instructions['amount'], 2) ?> €</strong> al teléfono:</p>
            <h3 class="text-center my-3"><?= e($instructions['phone']) ?></h3>
            <p class="mb-0"><strong>3.</strong> Concepto: <code><?= e($instructions['reference'] ?? $reference ?? '') ?></code></p>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <p class="mb-1"><strong>Red:</strong> <?= e($instructions['network'] ?? '') ?></p>
            <p class="mb-1"><strong>Importe:</strong> <?= number_format((float) ($instructions['amount'] ?? 0), 2) ?> <?= e($instructions['currency'] ?? 'EUR') ?></p>
            <p class="mb-1"><strong>Wallet:</strong></p>
            <code class="d-block p-2 bg-light mb-2 user-select-all"><?= e($instructions['wallet'] ?? '') ?></code>
            <p class="mb-0"><strong>Referencia:</strong> <code><?= e($instructions['reference'] ?? $reference ?? '') ?></code></p>
        </div>
        <?php endif; ?>

        <p class="text-muted small">Cuando el pago esté hecho, un administrador activará tu suscripción. Recibirás confirmación si hay email configurado.</p>
        <a href="/portal/subscription" class="btn btn-primary btn-sm">Volver a renovar</a>
        <a href="/portal" class="btn btn-outline-secondary btn-sm ms-1">Inicio</a>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
