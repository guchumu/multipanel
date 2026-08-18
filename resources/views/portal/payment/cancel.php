<?php ob_start(); ?>
<div class="card portal-card text-center p-4 p-md-5">
    <i class="bi bi-x-circle text-warning portal-result-icon"></i>
    <h1 class="h4 mt-3">Pago cancelado</h1>
    <p class="text-muted mb-4">
        No se ha cobrado nada. Puedes volver a intentarlo cuando quieras
        o contactar con soporte si necesitas ayuda.
    </p>
    <div class="d-grid gap-2 col-md-6 mx-auto">
        <a href="/portal/subscription" class="btn btn-primary">Intentar de nuevo</a>
        <a href="/portal" class="btn btn-outline-secondary btn-sm">Volver al inicio</a>
        <a href="/portal/tickets/create" class="btn btn-link btn-sm">Abrir ticket de soporte</a>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
