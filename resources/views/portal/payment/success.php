<?php ob_start(); ?>
<div class="card portal-card text-center p-4 p-md-5">
    <i class="bi bi-check-circle-fill text-success portal-result-icon"></i>
    <h1 class="h4 mt-3">¡Pago completado!</h1>
    <p class="text-muted mb-4">
        Gracias. Si el cobro se confirma correctamente, tu acceso se ampliará en breve
        (normalmente en unos minutos). Puedes cerrar esta página o volver al inicio.
    </p>
    <div class="d-grid gap-2 col-md-6 mx-auto">
        <a href="/portal" class="btn btn-primary">Volver al inicio</a>
        <a href="/portal/subscription" class="btn btn-outline-secondary btn-sm">Ver renovación</a>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
