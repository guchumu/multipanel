<?php
$preferredServerId = isset($preferredServerId) ? (int) $preferredServerId : 0;
$defaultPlexServerId = isset($defaultPlexServerId) ? (int) $defaultPlexServerId : 0;
$defaultJellyfinServerId = isset($defaultJellyfinServerId) ? (int) $defaultJellyfinServerId : 0;
ob_start();
?>
<div class="mb-4">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Añadir usuarios por email</h4>
    <p class="text-muted mb-0">Asigna uno o varios emails a un servidor con un periodo de suscripción.</p>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/media-users/bulk">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select id="server-type-pref" class="form-select">
                        <option value="">Cualquiera</option>
                        <option value="plex">Plex</option>
                        <option value="jellyfin">Jellyfin</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Servidor *</label>
                    <select name="server_id" id="server_id" class="form-select" required
                            data-default-plex="<?= $defaultPlexServerId > 0 ? $defaultPlexServerId : '' ?>"
                            data-default-jellyfin="<?= $defaultJellyfinServerId > 0 ? $defaultJellyfinServerId : '' ?>">
                        <option value="">Seleccionar servidor...</option>
                        <?php foreach ($servers as $server): ?>
                        <option value="<?= (int) $server->id ?>"
                                data-type="<?= e($server->type) ?>"
                                <?= ($preferredServerId > 0 && (int) $server->id === $preferredServerId) ? 'selected' : '' ?>>
                            <?= e($server->name) ?> (<?= e(strtoupper($server->type)) ?>)<?= $server->isDefault() ? ' ★ predeterminado' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Periodo *</label>
                    <select name="period" class="form-select" required>
                        <?php foreach ($periods as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $key === '1m' ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Emails *</label>
                    <textarea name="emails" class="form-control font-monospace" rows="10" required
                              placeholder="Un email por línea, o separados por comas&#10;usuario1@ejemplo.com&#10;usuario2@ejemplo.com"></textarea>
                    <div class="form-text">
                        Si el email ya existe, se actualizará su servidor y fecha de expiración.
                        En servidores <strong>Plex</strong> se enviará automáticamente la invitación a cada email con acceso a todas las bibliotecas;
                        el usuario pasará a "Activo" solo, sin acción manual, en cuanto Plex detecte que ha aceptado (próxima sincronización de servidor).
                        En <strong>Jellyfin</strong> se crea la cuenta al instante con una contraseña generada.
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Añadir usuarios</button>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
(function () {
    const typeSel = document.getElementById('server-type-pref');
    const serverSel = document.getElementById('server_id');
    if (!typeSel || !serverSel) return;

    function applyType(type) {
        const options = Array.from(serverSel.options);
        options.forEach(opt => {
            if (!opt.value) {
                opt.hidden = false;
                return;
            }
            const t = opt.dataset.type || '';
            opt.hidden = type !== '' && t !== type;
        });
        if (!type) return;
        const preferred = type === 'plex'
            ? serverSel.dataset.defaultPlex
            : serverSel.dataset.defaultJellyfin;
        if (preferred) {
            serverSel.value = preferred;
            return;
        }
        const firstVisible = options.find(o => o.value && !o.hidden);
        if (firstVisible) serverSel.value = firstVisible.value;
    }

    const selected = serverSel.selectedOptions[0];
    if (selected?.dataset?.type) {
        typeSel.value = selected.dataset.type;
    }

    typeSel.addEventListener('change', () => applyType(typeSel.value));
})();
</script>
JS;
include base_path('resources/views/layouts/app.php');
?>
