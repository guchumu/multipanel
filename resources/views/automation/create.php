<?php ob_start(); ?>
<div class="mb-4">
    <a href="/automation" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Nueva regla de automatización</h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/automation">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" class="form-control" required placeholder="Ej: Suspender por impago 5 días">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Prioridad</label>
                    <input type="number" name="priority" class="form-control" value="10">
                </div>
                <div class="col-12">
                    <label class="form-label">Descripción</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Evento trigger *</label>
                    <select name="trigger_event" class="form-select" required>
                        <option value="payment.overdue">Pago vencido</option>
                        <option value="payment.received">Pago recibido</option>
                        <option value="user.expired">Usuario expirado</option>
                        <option value="server.offline">Servidor offline</option>
                        <option value="cron.daily">Cron diario</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Acción</label>
                    <select name="action_type" class="form-select">
                        <option value="suspend_user">Suspender usuario</option>
                        <option value="delete_user">Eliminar usuario</option>
                        <option value="activate_user">Activar usuario</option>
                        <option value="notify">Enviar notificación</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Título notificación</label>
                    <input type="text" name="action_title" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mensaje notificación</label>
                    <input type="text" name="action_message" class="form-control">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="is_active" checked>
                        <label class="form-check-label" for="is_active">Regla activa</label>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Crear regla</button>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
