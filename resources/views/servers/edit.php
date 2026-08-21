<?php ob_start(); ?>
<div class="mb-4">
    <a href="/servers/<?= e($server->uuid) ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver al servidor</a>
    <h4 class="mt-2">Editar servidor</h4>
    <p class="text-muted small mb-0"><?= e($server->name) ?> · <?= e(strtoupper($server->type)) ?></p>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/servers/<?= e($server->uuid) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_method" value="PUT">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" class="form-control" value="<?= e($server->name) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo *</label>
                    <select name="type" class="form-select" id="serverType" required>
                        <option value="plex" <?= $server->type === 'plex' ? 'selected' : '' ?>>Plex</option>
                        <option value="jellyfin" <?= $server->type === 'jellyfin' ? 'selected' : '' ?>>Jellyfin</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">URL / Host *</label>
                    <input type="text" name="url" class="form-control" value="<?= e($server->displayHost()) ?>" required>
                    <div class="form-text">Solo el dominio (ej. <code>lunasea.mooo.com</code>), sin puerto. Plex usa puerto <strong>32400</strong>.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Puerto *</label>
                    <input type="number" name="port" class="form-control" value="<?= (int) $server->port ?>" required>
                </div>
                <div class="col-md-6" id="plexTokenField">
                    <label class="form-label">Token Plex</label>
                    <input type="text" name="token" class="form-control" placeholder="Dejar vacío para no cambiar" autocomplete="off">
                    <?php if ($server->token): ?><div class="form-text">Actual: <?= e(substr((string) $server->token, 0, 6)) ?>…</div><?php endif; ?>
                </div>
                <div class="col-md-6 d-none" id="jellyfinKeyField">
                    <label class="form-label">API Key Jellyfin</label>
                    <input type="text" name="api_key" class="form-control" placeholder="Dejar vacío para no cambiar" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ubicación</label>
                    <input type="text" name="location" class="form-control" value="<?= e($server->location ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Intervalo comprobación (min)</label>
                    <input type="number" name="check_interval" class="form-control" value="<?= (int) ($server->check_interval_minutes ?? 5) ?>" min="1">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Cupo de usuarios</label>
                    <input type="number" name="user_quota" class="form-control" min="0" max="100000"
                           value="<?= (int) ($server->user_quota ?? 0) ?>"
                           placeholder="0 = sin límite">
                    <div class="form-text">0 = sin límite. Altas nuevas (portal, registro, cuentas extra) no entran si está lleno. Renovar a quien ya está aquí no cuenta plaza nueva.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Descripción</label>
                    <textarea name="description" class="form-control" rows="2"><?= e($server->description ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input type="checkbox" name="ssl" class="form-check-input" id="fieldSsl" value="1" <?= $server->ssl ? 'checked' : '' ?>>
                        <label class="form-check-label" for="fieldSsl">Usar SSL/HTTPS</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input type="checkbox" name="sync_after" class="form-check-input" id="syncAfter" value="1" checked>
                        <label class="form-check-label" for="syncAfter">Sincronizar tras guardar</label>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
                <a href="/servers/<?= e($server->uuid) ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-danger border-opacity-25 mt-4">
    <div class="card-body">
        <h6 class="text-danger">Zona peligrosa</h6>
        <p class="text-muted small mb-3">Eliminar el servidor no borra los usuarios media ya importados, pero dejarán de sincronizarse con este servidor.</p>
        <form method="POST" action="/servers/<?= e($server->uuid) ?>" onsubmit="return confirm('¿Eliminar este servidor?');">
            <?= csrf_field() ?>
            <input type="hidden" name="_method" value="DELETE">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Eliminar servidor</button>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
const typeSelect = document.getElementById('serverType');
function toggleTypeFields() {
    const isPlex = typeSelect.value === 'plex';
    document.getElementById('plexTokenField').classList.toggle('d-none', !isPlex);
    document.getElementById('jellyfinKeyField').classList.toggle('d-none', isPlex);
}
typeSelect.addEventListener('change', toggleTypeFields);
toggleTypeFields();
</script>
JS;
include base_path('resources/views/layouts/app.php');
