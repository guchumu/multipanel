<?php ob_start(); ?>
<div class="mb-4">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
    <h4 class="mt-2">Mensaje masivo Telegram</h4>
    <p class="text-muted small">Destinatarios con Telegram activos (estimado): <strong><?= (int) $recipientCount ?></strong></p>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/media-users/broadcast">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small">Estado</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active">Activos</option>
                        <option value="">Todos</option>
                        <option value="suspended">Suspendidos</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Servidor</label>
                    <select name="server_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server->id ?>"><?= e($server->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small">Título</label>
                    <input type="text" name="title" class="form-control" value="Oferta especial" required>
                </div>
                <div class="col-12">
                    <label class="form-label small">Mensaje</label>
                    <textarea name="body" class="form-control" rows="8" required placeholder="Hola {display_name}, …"></textarea>
                    <p class="small text-muted mt-1">Variables: {username}, {email}, {display_name}, {end_date}, {server_name}</p>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('¿Enviar mensaje masivo por Telegram?')">
                        <i class="bi bi-megaphone me-1"></i>Enviar masivo
                    </button>
                    <a href="/settings/notifications" class="btn btn-outline-secondary ms-2">Editar avisos de caducidad</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
