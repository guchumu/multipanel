<?php ob_start(); ?>
<h4 class="text-white mb-4">Instrucciones de pago — <?= e(ucfirst($gateway)) ?></h4>
<div class="card portal-card">
    <div class="card-body">
        <h5><?= e($plan['name']) ?> — <?= number_format((float) $plan['price'], 2) ?> <?= e($plan['currency']) ?></h5>

        <?php if ($gateway === 'bizum'): ?>
        <div class="alert alert-info">
            <p class="mb-1"><strong>1.</strong> Abre la app Bizum en tu móvil</p>
            <p class="mb-1"><strong>2.</strong> Envía <strong><?= number_format((float) $instructions['amount'], 2) ?> €</strong> al teléfono:</p>
            <h4 class="text-center my-3"><?= e($instructions['phone']) ?></h4>
            <p class="mb-0"><strong>3.</strong> Concepto: <code><?= e($instructions['reference']) ?></code></p>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <p class="mb-1"><strong>Red:</strong> <?= e($instructions['network']) ?></p>
            <p class="mb-1"><strong>Importe:</strong> <?= number_format((float) $instructions['amount'], 2) ?> <?= e($instructions['currency']) ?></p>
            <p class="mb-1"><strong>Wallet:</strong></p>
            <code class="d-block p-2 bg-light mb-2 user-select-all"><?= e($instructions['wallet']) ?></code>
            <p class="mb-0"><strong>Referencia:</strong> <code><?= e($instructions['reference']) ?></code></p>
        </div>
        <?php endif; ?>

        <p class="text-muted small">Una vez realizado el pago, un administrador activará tu suscripción. Recibirás confirmación por email.</p>
        <a href="/portal/subscription" class="btn btn-outline-light btn-sm">Volver a planes</a>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
