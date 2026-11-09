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
                    <label class="form-label">Token Plex (manual)</label>
                    <input type="text" name="token" class="form-control" id="plexTokenInput" placeholder="Dejar vacío para no cambiar" autocomplete="off">
                    <div class="form-text" id="plexTokenPreview"><?php if ($server->token): ?>Actual: <?= e(substr((string) $server->token, 0, 6)) ?>…<?php else: ?>Sin token guardado<?php endif; ?></div>
                    <div class="form-text">
                        Guía C:
                        <a href="https://app.plex.tv/desktop/#!/settings/account" target="_blank" rel="noopener">cuenta Plex</a>
                        · tras iniciar sesión en plex.tv, en DevTools → Network busca
                        <code>X-Plex-Token</code> en las cabeceras o en la URL.
                        Preferible usar el bloque de abajo (PIN o login).
                    </div>
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

<?php if ($server->type === 'plex'): ?>
<div class="card border-0 shadow-sm mt-4" id="plexTokenRefreshCard">
    <div class="card-body">
        <h6 class="mb-1"><i class="bi bi-key me-1"></i>Actualizar token Plex</h6>
        <p class="text-muted small mb-3">
            Si el token caducó o lo perdiste: <strong>B (recomendado)</strong> autoriza con enlace (funciona con 2FA).
            <strong>A</strong> usa email/contraseña (sin 2FA). <strong>C</strong> pega el token a mano arriba.
        </p>
        <div id="tokenRefreshStatus" class="alert d-none py-2 small" role="status"></div>

        <div class="border rounded p-3 mb-3 bg-body-tertiary">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <strong>B · Enlace / PIN</strong>
                    <div class="text-muted small">Abre Plex, inicia sesión y el panel detecta el token solo.</div>
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="btnPinStart">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Generar enlace
                </button>
            </div>
            <div id="pinPanel" class="d-none">
                <p class="mb-2 small">
                    Código: <code id="pinCode" class="fs-6"></code>
                    · <a id="pinAuthLink" href="#" target="_blank" rel="noopener">Abrir autorización Plex</a>
                </p>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="spinner-border spinner-border-sm text-primary" role="status" id="pinSpinner"></div>
                    <span class="small text-muted" id="pinWaitMsg">Esperando que autorices en Plex…</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPinCancel">Cancelar</button>
                </div>
            </div>
        </div>

        <div class="border rounded p-3">
            <strong>A · Email y contraseña</strong>
            <div class="text-muted small mb-2">No funciona si la cuenta tiene 2FA; en ese caso usa B.</div>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="plexLoginEmail">Email Plex</label>
                    <input type="email" class="form-control form-control-sm" id="plexLoginEmail" autocomplete="username">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="plexLoginPass">Contraseña</label>
                    <input type="password" class="form-control form-control-sm" id="plexLoginPass" autocomplete="current-password">
                </div>
                <div class="col-md-4">
                    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnLoginToken">
                        <i class="bi bi-arrow-repeat me-1"></i>Obtener y guardar token
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
$serverUuid = e($server->uuid);
$csrfToken = e(csrf_token());
$scripts = <<<JS
<script>
const typeSelect = document.getElementById('serverType');
function toggleTypeFields() {
    const isPlex = typeSelect.value === 'plex';
    document.getElementById('plexTokenField').classList.toggle('d-none', !isPlex);
    document.getElementById('jellyfinKeyField').classList.toggle('d-none', isPlex);
    const refresh = document.getElementById('plexTokenRefreshCard');
    if (refresh) refresh.classList.toggle('d-none', !isPlex);
}
typeSelect.addEventListener('change', toggleTypeFields);
toggleTypeFields();

(function () {
    const uuid = '{$serverUuid}';
    const csrf = '{$csrfToken}';
    const statusEl = document.getElementById('tokenRefreshStatus');
    if (!statusEl) return;

    let pollTimer = null;
    let currentPinId = null;

    function showStatus(ok, msg) {
        statusEl.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
        statusEl.classList.add(ok === true ? 'alert-success' : (ok === false ? 'alert-danger' : 'alert-info'));
        statusEl.textContent = msg;
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(Object.assign({ _token: csrf }, body || {}))
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok && data.ok !== true) {
            throw new Error(data.message || data.error || ('HTTP ' + res.status));
        }
        return data;
    }

    function stopPoll() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        currentPinId = null;
        const panel = document.getElementById('pinPanel');
        if (panel) panel.classList.add('d-none');
    }

    document.getElementById('btnPinStart')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnPinStart');
        btn.disabled = true;
        showStatus(null, 'Generando enlace…');
        try {
            const data = await postJson('/servers/' + uuid + '/token/pin/start', {});
            currentPinId = data.id;
            document.getElementById('pinCode').textContent = data.code || '';
            const link = document.getElementById('pinAuthLink');
            link.href = data.auth_url;
            document.getElementById('pinPanel').classList.remove('d-none');
            document.getElementById('pinWaitMsg').textContent = 'Esperando que autorices en Plex…';
            showStatus(null, 'Abre el enlace, inicia sesión en Plex y vuelve aquí.');
            window.open(data.auth_url, '_blank', 'noopener');
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(async () => {
                try {
                    const poll = await postJson('/servers/' + uuid + '/token/pin/poll', { pin_id: currentPinId });
                    if (poll.pending) return;
                    stopPoll();
                    showStatus(true, poll.message || 'Token actualizado.');
                    const tip = document.getElementById('plexTokenPreview');
                    if (tip && poll.token_preview) tip.textContent = 'Actual: ' + poll.token_preview;
                } catch (err) {
                    stopPoll();
                    showStatus(false, err.message || 'Error al comprobar PIN');
                }
            }, 2500);
        } catch (err) {
            showStatus(false, err.message || 'No se pudo crear el PIN');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('btnPinCancel')?.addEventListener('click', () => {
        stopPoll();
        showStatus(null, 'Autorización cancelada.');
    });

    document.getElementById('btnLoginToken')?.addEventListener('click', async () => {
        const login = document.getElementById('plexLoginEmail').value.trim();
        const password = document.getElementById('plexLoginPass').value;
        if (!login || !password) {
            showStatus(false, 'Indica email y contraseña Plex.');
            return;
        }
        const btn = document.getElementById('btnLoginToken');
        btn.disabled = true;
        showStatus(null, 'Obteniendo token…');
        try {
            const data = await postJson('/servers/' + uuid + '/token/login', { login, password });
            document.getElementById('plexLoginPass').value = '';
            const tip = document.getElementById('plexTokenPreview');
            if (tip && data.token_preview) tip.textContent = 'Actual: ' + data.token_preview;
            showStatus(true, data.message || 'Token actualizado.');
        } catch (err) {
            showStatus(false, err.message || 'Login fallido');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
JS;
include base_path('resources/views/layouts/app.php');
