<?php ob_start(); ?>
<div class="card portal-card text-center p-4 p-md-5">
    <i class="bi bi-check-circle-fill text-success portal-result-icon"></i>
    <h1 class="h4 mt-3">¡Pago completado!</h1>
    <p class="text-muted mb-4">
        ¡Bien! Cuando el pago se confirme, se suma el tiempo a tu cuenta.
        Si pediste cuentas individuales extra, las apuntamos con los emails que escribiste.
    </p>
    <div class="d-grid gap-2 col-md-6 mx-auto">
        <a href="/portal" class="btn btn-primary">Volver al inicio</a>
        <a href="/portal/subscription" class="btn btn-outline-secondary btn-sm">Ver renovación</a>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
