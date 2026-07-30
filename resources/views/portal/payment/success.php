<?php ob_start(); ?>
<div class="card portal-card text-center p-5">
    <i class="bi bi-check-circle text-success" style="font-size:4rem"></i>
    <h4 class="mt-3">¡Pago completado!</h4>
    <p class="text-muted">Tu suscripción se activará en breve.</p>
    <a href="/portal" class="btn btn-primary">Volver al portal</a>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
