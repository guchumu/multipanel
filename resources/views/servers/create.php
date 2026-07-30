<?php ob_start(); ?>
<div class="mb-4">
    <a href="/servers" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Nuevo servidor</h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/servers">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo *</label>
                    <select name="type" class="form-select" required>
                        <option value="plex">Plex</option>
                        <option value="jellyfin">Jellyfin</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">URL / Host *</label>
                    <input type="text" name="url" class="form-control" placeholder="192.168.1.100 o plex.example.com" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Puerto *</label>
                    <input type="number" name="port" class="form-control" value="32400" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Token Plex</label>
                    <input type="text" name="token" class="form-control" placeholder="Para servidores Plex">
                </div>
                <div class="col-md-6">
                    <label class="form-label">API Key Jellyfin</label>
                    <input type="text" name="api_key" class="form-control" placeholder="Para servidores Jellyfin">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ubicación</label>
                    <input type="text" name="location" class="form-control" placeholder="Madrid, ES">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Intervalo comprobación (min)</label>
                    <input type="number" name="check_interval" class="form-control" value="5" min="1">
                </div>
                <div class="col-12">
                    <label class="form-label">Descripción</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="ssl" class="form-check-input" id="ssl">
                        <label class="form-check-label" for="ssl">Usar SSL/HTTPS</label>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Guardar servidor</button>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
