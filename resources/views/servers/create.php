<?php ob_start(); ?>
<div class="mb-4">
    <a href="/servers" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Nuevo servidor</h4>
    <p class="text-muted small mb-0">Tras guardar se sincroniza automáticamente e importa todos los usuarios del servidor. Tú solo añades las fechas de expiración en Usuarios Media.</p>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/servers">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" class="form-control" placeholder="Mi Plex Casa" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo *</label>
                    <select name="type" class="form-select" id="serverType" required>
                        <option value="plex">Plex</option>
                        <option value="jellyfin">Jellyfin</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">URL / Host *</label>
                    <input type="text" name="url" class="form-control" placeholder="192.168.1.100 o plex.example.com" required>
                    <div class="form-text">IP o dominio donde corre el servidor (sin http://).</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Puerto *</label>
                    <input type="number" name="port" class="form-control" id="serverPort" value="32400" required>
                </div>
                <div class="col-md-6" id="plexTokenField">
                    <label class="form-label">Token Plex *</label>
                    <input type="text" name="token" class="form-control" placeholder="X-Plex-Token del propietario">
                    <div class="form-text">Plex → Configuración → Cuenta → Token (o desde app.plex.tv/desktop).</div>
                </div>
                <div class="col-md-6 d-none" id="jellyfinKeyField">
                    <label class="form-label">API Key Jellyfin *</label>
                    <input type="text" name="api_key" class="form-control" placeholder="Dashboard → API Keys">
                    <div class="form-text">Panel Jellyfin → API Keys → crear clave de administrador.</div>
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
                <button type="submit" class="btn btn-primary">Guardar y sincronizar usuarios</button>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
const typeSelect = document.getElementById('serverType');
const portInput = document.getElementById('serverPort');
const plexField = document.getElementById('plexTokenField');
const jellyfinField = document.getElementById('jellyfinKeyField');

function toggleTypeFields() {
    const isPlex = typeSelect.value === 'plex';
    plexField.classList.toggle('d-none', !isPlex);
    jellyfinField.classList.toggle('d-none', isPlex);
    if (portInput.value === '32400' || portInput.value === '8096') {
        portInput.value = isPlex ? '32400' : '8096';
    }
}
typeSelect.addEventListener('change', toggleTypeFields);
toggleTypeFields();
</script>
JS;
include base_path('resources/views/layouts/app.php');
