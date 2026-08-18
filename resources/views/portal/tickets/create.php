<?php ob_start(); ?>
<h1 class="portal-page-title">Nuevo ticket</h1>
<p class="portal-page-lead">Cuéntanos el problema y te responderemos lo antes posible.</p>

<div class="card portal-card">
    <div class="card-body">
        <form method="POST" action="/portal/tickets">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="subject">Asunto</label>
                <input id="subject" name="subject" class="form-control" required maxlength="255" placeholder="Ej. No puedo reproducir">
            </div>
            <div class="mb-3">
                <label class="form-label" for="priority">Prioridad</label>
                <select id="priority" name="priority" class="form-select">
                    <option value="low">Baja</option>
                    <option value="medium" selected>Media</option>
                    <option value="high">Alta</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="message">Mensaje</label>
                <textarea id="message" name="message" class="form-control" rows="5" required placeholder="Describe qué ocurre…"></textarea>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Enviar ticket</button>
                <a href="/portal/tickets" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
