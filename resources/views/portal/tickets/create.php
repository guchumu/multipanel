<?php ob_start(); ?>
<h4 class="text-white mb-4">Nuevo ticket de soporte</h4>
<div class="card portal-card">
    <div class="card-body">
        <form method="POST" action="/portal/tickets">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Asunto</label><input name="subject" class="form-control" required></div>
            <div class="mb-3">
                <label class="form-label">Prioridad</label>
                <select name="priority" class="form-select">
                    <option value="low">Baja</option>
                    <option value="medium" selected>Media</option>
                    <option value="high">Alta</option>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Mensaje</label><textarea name="message" class="form-control" rows="5" required></textarea></div>
            <button class="btn btn-primary">Enviar ticket</button>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
