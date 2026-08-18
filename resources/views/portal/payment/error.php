<?php ob_start(); ?>
<div class="card portal-card text-center p-4 p-md-5">
    <i class="bi bi-exclamation-triangle text-danger portal-result-icon"></i>
    <h1 class="h4 mt-3">No se pudo iniciar el pago</h1>
    <p class="text-muted mb-2"><?= e($error ?? 'Ha ocurrido un error.') ?></p>
    <p class="small text-muted mb-4">Prueba otra opción o contacta con soporte.</p>
    <div class="d-grid gap-2 col-md-6 mx-auto">
        <a href="/portal/subscription" class="btn btn-primary">Volver a renovar</a>
        <a href="/portal/tickets/create" class="btn btn-outline-secondary btn-sm">Contactar soporte</a>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
