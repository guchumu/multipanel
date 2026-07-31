<?php ob_start(); ?>
<div class="mb-4">
    <a href="/servers" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <h4 class="mt-2">Nuevo servidor</h4>
    <p class="text-muted small mb-0">Conecta con usuario y contraseña para obtener token y datos automáticamente, o rellena manualmente.</p>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabAuto" type="button">Usuario y contraseña</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabManual" type="button">Manual (token/API key)</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tabAuto">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" id="discoverType">
                            <option value="plex">Plex</option>
                            <option value="jellyfin">Jellyfin</option>
                        </select>
                    </div>
                    <div class="col-md-4 jellyfin-only d-none">
                        <label class="form-label">Host Jellyfin</label>
                        <input type="text" class="form-control" id="discoverHost" placeholder="192.168.1.50">
                    </div>
                    <div class="col-md-2 jellyfin-only d-none">
                        <label class="form-label">Puerto</label>
                        <input type="number" class="form-control" id="discoverPort" value="8096">
                    </div>
                    <div class="col-md-2 jellyfin-only d-none d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" id="discoverSsl">
                            <label class="form-check-label" for="discoverSsl">SSL</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" id="discoverLoginLabel">Email Plex</label>
                        <input type="text" class="form-control" id="discoverLogin" autocomplete="username">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="discoverPassword" autocomplete="current-password">
                    </div>
                </div>
                <button type="button" class="btn btn-outline-primary mt-3" id="btnDiscover">
                    <i class="bi bi-search me-1"></i>Buscar servidores
                </button>
                <div id="discoverError" class="alert alert-danger mt-3 d-none"></div>
            </div>
        </div>

        <div id="discoverResults" class="d-none">
            <h6 class="mb-3">Servidores encontrados — selecciona uno:</h6>
            <div class="list-group mb-3" id="discoverList"></div>
        </div>
    </div>

    <div class="tab-pane fade" id="tabManual">
        <p class="text-muted small">Introduce token Plex o API Key Jellyfin manualmente.</p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/servers" id="serverForm">
            <?= csrf_field() ?>
            <input type="hidden" name="machine_id" id="fieldMachineId" value="">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" id="fieldName" class="form-control" placeholder="Mi Plex Casa" required>
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
                    <input type="text" name="url" id="fieldUrl" class="form-control" placeholder="lunasea.mooo.com" required>
                    <div class="form-text">Solo el <strong>dominio</strong>, sin <code>:32400</code>. El puerto va en el campo de al lado (Plex: 32400).</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Puerto *</label>
                    <input type="number" name="port" id="fieldPort" class="form-control" value="32400" required>
                </div>
                <div class="col-md-6" id="plexTokenField">
                    <label class="form-label">Token Plex</label>
                    <input type="text" name="token" id="fieldToken" class="form-control">
                </div>
                <div class="col-md-6 d-none" id="jellyfinKeyField">
                    <label class="form-label">API Key Jellyfin</label>
                    <input type="text" name="api_key" id="fieldApiKey" class="form-control">
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
                        <input type="checkbox" name="ssl" class="form-check-input" id="fieldSsl">
                        <label class="form-check-label" for="fieldSsl">Usar SSL/HTTPS</label>
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
const csrf = document.querySelector('meta[name=csrf-token]').content;
const typeSelect = document.getElementById('serverType');
const discoverType = document.getElementById('discoverType');
const fieldSsl = document.getElementById('fieldSsl');

function toggleTypeFields() {
    const isPlex = typeSelect.value === 'plex';
    document.getElementById('plexTokenField').classList.toggle('d-none', !isPlex);
    document.getElementById('jellyfinKeyField').classList.toggle('d-none', isPlex);
}

function toggleDiscoverFields() {
    const isPlex = discoverType.value === 'plex';
    document.querySelectorAll('.jellyfin-only').forEach(el => el.classList.toggle('d-none', isPlex));
    document.getElementById('discoverLoginLabel').textContent = isPlex ? 'Email Plex' : 'Usuario Jellyfin';
    typeSelect.value = discoverType.value;
    toggleTypeFields();
}

function fillForm(server) {
    document.getElementById('fieldName').value = server.name || '';
    document.getElementById('fieldUrl').value = server.url || '';
    document.getElementById('fieldPort').value = server.port || 32400;
    document.getElementById('fieldMachineId').value = server.client_id || server.machine_id || '';
    fieldSsl.checked = !!server.ssl;
    typeSelect.value = server.type || 'plex';
    toggleTypeFields();
    if (server.type === 'jellyfin') {
        document.getElementById('fieldApiKey').value = server.api_key || '';
        document.getElementById('fieldToken').value = '';
    } else {
        document.getElementById('fieldToken').value = server.token || '';
        document.getElementById('fieldApiKey').value = '';
    }
    document.getElementById('serverForm').scrollIntoView({ behavior: 'smooth' });
}

discoverType.addEventListener('change', toggleDiscoverFields);
typeSelect.addEventListener('change', toggleTypeFields);
toggleDiscoverFields();
toggleTypeFields();

document.getElementById('btnDiscover').addEventListener('click', async () => {
    const errBox = document.getElementById('discoverError');
    const results = document.getElementById('discoverResults');
    const list = document.getElementById('discoverList');
    errBox.classList.add('d-none');
    results.classList.add('d-none');
    list.innerHTML = '';

    const isPlex = discoverType.value === 'plex';
    const url = isPlex ? '/servers/discover/plex' : '/servers/discover/jellyfin';
    const body = isPlex
        ? { login: document.getElementById('discoverLogin').value, password: document.getElementById('discoverPassword').value }
        : {
            url: document.getElementById('discoverHost').value,
            port: document.getElementById('discoverPort').value,
            ssl: document.getElementById('discoverSsl').checked,
            username: document.getElementById('discoverLogin').value,
            password: document.getElementById('discoverPassword').value
        };

    const btn = document.getElementById('btnDiscover');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Buscando...';

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body)
        });
        let data;
        try {
            data = await res.json();
        } catch (e) {
            errBox.textContent = 'Respuesta inválida del servidor (¿sesión caducada?). Recarga la página.';
            errBox.classList.remove('d-none');
            return;
        }
        if (!res.ok || data.error) {
            errBox.textContent = data.error || 'Error al buscar servidores';
            errBox.classList.remove('d-none');
            return;
        }

        const servers = isPlex ? data.servers : [data.server];
        const remoteFirst = [...servers].sort((a, b) => ((a.local ? 1 : 0) - (b.local ? 1 : 0)));
        remoteFirst.forEach((s, i) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action' + (s.local ? ' list-group-item-warning' : '');
            const local = s.local ? ' · local (no recomendado en VPS)' : ' · remoto';
            item.innerHTML = `<strong>${s.name}</strong><br><small class="text-muted">${s.url}:${s.port}${local}</small>`;
            item.addEventListener('click', () => fillForm({ ...s, type: isPlex ? 'plex' : 'jellyfin', token: data.token, api_key: s.api_key || data.api_key }));
            list.appendChild(item);
        });
        results.classList.remove('d-none');

        const preferred = remoteFirst.find(s => !s.local) || remoteFirst[0];
        if (preferred) fillForm({ ...preferred, type: isPlex ? 'plex' : 'jellyfin', token: data.token, api_key: preferred.api_key || data.api_key });
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-search me-1"></i>Buscar servidores';
    }
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
