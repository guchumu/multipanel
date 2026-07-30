<?php ob_start(); ?>
<h4 class="text-white mb-4">Mi suscripción</h4>
<div class="row g-4">
    <?php foreach ($plans as $plan): ?>
    <div class="col-md-4">
        <div class="card portal-card h-100">
            <div class="card-body text-center">
                <h5><?= e($plan['name']) ?></h5>
                <h2 class="text-primary"><?= number_format((float)$plan['price'], 2) ?> €</h2>
                <p class="text-muted">/ <?= e($plan['interval']) ?></p>
                <ul class="list-unstyled small text-start mb-3">
                    <li><i class="bi bi-check text-success"></i> <?= (int)$plan['max_streams'] ?> streams</li>
                    <li><i class="bi bi-check text-success"></i> <?= (int)$plan['max_devices'] ?> dispositivos</li>
                    <?php if ($plan['trial_days']): ?>
                    <li><i class="bi bi-gift text-info"></i> <?= (int)$plan['trial_days'] ?> días prueba</li>
                    <?php endif; ?>
                </ul>
                <form method="POST" action="/portal/payment/checkout">
                    <?= csrf_field() ?>
                    <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                    <div class="d-grid gap-2">
                        <button name="gateway" value="stripe" class="btn btn-primary btn-sm">Pagar con Stripe</button>
                        <button name="gateway" value="paypal" class="btn btn-outline-primary btn-sm">Pagar con PayPal</button>
                        <button name="gateway" value="bizum" class="btn btn-outline-success btn-sm">Pagar con Bizum</button>
                        <button name="gateway" value="crypto" class="btn btn-outline-warning btn-sm">Pagar con Crypto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
