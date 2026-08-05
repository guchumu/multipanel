<?php
$playMethodLabel = static function (string $method): string {
    return match ($method) {
        'direct_play' => 'Direct Play',
        'direct_stream' => 'Direct Stream',
        'transcode' => 'Transcode',
        default => ucfirst(str_replace('_', ' ', $method)),
    };
};

$playMethodBadge = static function (string $method): string {
    return match ($method) {
        'direct_play' => 'success',
        'direct_stream' => 'info',
        'transcode' => 'warning',
        default => 'secondary',
    };
};

$buildFilterUrl = static function (?int $serverId): string {
    if ($serverId === null) {
        return '/activity';
    }
    return '/activity?' . http_build_query(['server_id' => $serverId]);
};

/** @var array<int, array{id:int,title:string,body:string,is_default:int}> $stopMessages */
$stopMessages = $stopMessages ?? [];

$renderSessionCard = static function (array $session) use ($playMethodLabel, $playMethodBadge, $stopMessages): void {
    include base_path('resources/views/activity/_session_card.php');
};

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">En directo</h4>
        <small class="text-muted">
            <?php if ($currentServerId): ?>
            Reproducciones en un servidor
            <?php else: ?>
            Vista conjunta de todos los servidores
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="/media-users/stream-violations" class="btn btn-outline-secondary btn-sm" title="Incumplimientos de streams">
            <i class="bi bi-exclamation-octagon me-1"></i>Límites
        </a>
        <a href="/settings/stop-messages" class="btn btn-outline-secondary btn-sm" title="Gestionar mensajes al detener">
            <i class="bi bi-chat-left-text me-1"></i>Mensajes
        </a>
        <span class="badge bg-primary" id="session-count"><?= (int) $totalCount ?> streams totales</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="refresh-btn" title="Actualizar"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
</div>

<div class="row g-3 mb-3" id="server-summary">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="<?= e($buildFilterUrl(null)) ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= !$currentServerId ? 'border-primary border-2' : '' ?>">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-muted">Conjunto</div>
                            <div class="fs-4 fw-semibold mb-0" data-stat="total"><?= (int) $totalCount ?></div>
                        </div>
                        <i class="bi bi-collection-play fs-3 text-primary opacity-75"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php foreach ($serverStats as $stat): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="<?= e($buildFilterUrl((int) $stat['id'])) ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= $currentServerId === (int) $stat['id'] ? 'border-primary border-2' : '' ?>">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <div class="small text-muted text-truncate"><?= e($stat['name']) ?></div>
                            <div class="fs-4 fw-semibold mb-0" data-stat-server="<?= (int) $stat['id'] ?>"><?= (int) $stat['count'] ?></div>
                            <span class="badge bg-<?= $stat['type'] === 'plex' ? 'warning' : 'info' ?> mt-1"><?= e(strtoupper($stat['type'])) ?></span>
                        </div>
                        <span class="badge bg-<?= $stat['status'] === 'online' ? 'success' : 'danger' ?>"><?= e($stat['status']) ?></span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <div class="btn-group btn-group-sm flex-wrap">
                <a href="<?= e($buildFilterUrl(null)) ?>" class="btn btn-outline-secondary <?= !$currentServerId ? 'active' : '' ?>">
                    Conjunto <span class="badge bg-secondary ms-1" id="badge-total"><?= (int) $totalCount ?></span>
                </a>
                <?php foreach ($serverStats as $stat): ?>
                <a href="<?= e($buildFilterUrl((int) $stat['id'])) ?>"
                   class="btn btn-outline-secondary <?= $currentServerId === (int) $stat['id'] ? 'active' : '' ?>">
                    <?= e($stat['name']) ?>
                    <span class="badge bg-secondary ms-1 badge-server" data-server-id="<?= (int) $stat['id'] ?>"><?= (int) $stat['count'] ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <form method="GET" action="/activity" class="d-flex gap-2 align-items-center ms-auto">
                <label class="small text-muted mb-0">Servidor:</label>
                <select name="server_id" class="form-select form-select-sm" style="min-width: 200px;" onchange="this.form.submit()">
                    <option value="">Conjunto (todos)</option>
                    <?php foreach ($servers as $server): ?>
                    <?php
                    $statCount = 0;
                    foreach ($serverStats as $stat) {
                        if ((int) $stat['id'] === (int) $server->id) {
                            $statCount = (int) $stat['count'];
                            break;
                        }
                    }
                    ?>
                    <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>>
                        <?= e($server->name) ?> (<?= $statCount ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</div>

<div id="sessions-container">
<?php if ($currentServerId): ?>
    <div class="row g-3" id="sessions-grid">
        <?php if (empty($sessions)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-tv fs-1 d-block mb-2"></i>
                    No hay reproducciones activas en este servidor
                </div>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($sessions as $session): ?>
        <?php $renderSessionCard($session); ?>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div id="sessions-grouped">
        <?php if (empty($grouped)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-tv fs-1 d-block mb-2"></i>
                No hay reproducciones activas
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($grouped as $group): ?>
        <div class="mb-4 server-group" data-server-id="<?= (int) $group['server_id'] ?>">
            <div class="d-flex align-items-center gap-2 mb-3">
                <h5 class="mb-0"><?= e($group['server_name']) ?></h5>
                <span class="badge bg-<?= $group['server_type'] === 'plex' ? 'warning' : 'info' ?>"><?= e(strtoupper($group['server_type'])) ?></span>
                <span class="badge bg-primary group-count"><?= count($group['sessions']) ?> streams</span>
                <a href="<?= e($buildFilterUrl((int) $group['server_id'])) ?>" class="btn btn-sm btn-outline-secondary ms-auto">Ver solo este</a>
            </div>
            <div class="row g-3">
                <?php foreach ($group['sessions'] as $session): ?>
                <?php $renderSessionCard($session); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php
$content = ob_get_clean();
$viewMode = $currentServerId ? 'server' : 'all';
$serverFilter = $currentServerId ? '?server_id=' . (int) $currentServerId : '';
$stopMessagesJson = json_encode(
    array_map(static fn (array $m): array => [
        'id' => (int) $m['id'],
        'title' => (string) $m['title'],
        'body' => (string) $m['body'],
        'is_default' => (int) $m['is_default'],
    ], $stopMessages),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($stopMessagesJson === false) {
    $stopMessagesJson = '[]';
}
$scripts = <<<JS
<script>
const viewMode = '{$viewMode}';
const playLabels = { direct_play: 'Direct Play', direct_stream: 'Direct Stream', transcode: 'Transcode' };
const playBadges = { direct_play: 'success', direct_stream: 'info', transcode: 'warning' };
const apiUrl = '/activity/api{$serverFilter}';
const stopMessages = {$stopMessagesJson};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

/** base64url sin padding — igual que StreamingActivityService::encodeThumbParam */
function toBase64Url(str) {
    const bytes = new TextEncoder().encode(String(str));
    let bin = '';
    bytes.forEach(b => { bin += String.fromCharCode(b); });
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * Misma construcción que /activity/thumbs-debug → proxy_url.
 * Reconstruye si thumb_url falta, es URL directa al PMS, o usa el legacy ?path=.
 */
function sessionThumbUrl(s) {
    const uuid = String(s.server_uuid || '');
    let url = String(s.thumb_url || '');

    if (url.includes('/activity/thumb/') && url.includes('?p=')) return url;
    if (url.includes('/activity/thumb/') && url.includes('?item=')) return url;

    if (uuid && s.art_path) {
        return '/activity/thumb/' + uuid + '?p=' + toBase64Url(s.art_path);
    }
    if (uuid && s.item_id) {
        return '/activity/thumb/' + uuid + '?item=' + encodeURIComponent(String(s.item_id));
    }

    const pathIdx = url.indexOf('?path=');
    if (pathIdx !== -1 && url.includes('/activity/thumb/')) {
        const uuidPart = url.slice(0, pathIdx).split('/activity/thumb/')[1] || '';
        const pathPart = url.slice(pathIdx + 6).split('&')[0];
        if (uuidPart && pathPart) {
            try {
                return '/activity/thumb/' + uuidPart + '?p=' + toBase64Url(decodeURIComponent(pathPart));
            } catch (e) { /* ignore */ }
        }
    }

    return url.includes('/activity/thumb/') ? url : '';
}

const thumbFallbackSrc = 'data:image/svg+xml,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300" viewBox="0 0 200 300">'
    + '<rect width="200" height="300" fill="#2b2f36"/>'
    + '<text x="100" y="150" fill="#9aa0a6" text-anchor="middle" font-family="sans-serif" font-size="14">Sin carátula</text>'
    + '</svg>'
);

function onSessionThumbError(img) {
    img.onerror = null;
    img.src = thumbFallbackSrc;
}

function thumbHtml(s) {
    const url = sessionThumbUrl(s);
    if (!url) {
        return '<div class="session-poster-fallback"><i class="bi bi-film fs-1"></i></div>';
    }
    return `<img src="\${escapeHtml(url)}" alt="" decoding="async" onerror="onSessionThumbError(this)">`;
}

function defaultStopBody() {
    const def = (stopMessages || []).find(m => Number(m.is_default) === 1);
    if (def) return String(def.body || '');
    return stopMessages.length ? String(stopMessages[0].body || '') : '';
}

function killPresetOptionsHtml() {
    let html = '<option value="">Personalizado / sin mensaje</option>';
    (stopMessages || []).forEach(m => {
        html += `<option value="\${m.id}" \${Number(m.is_default) === 1 ? 'selected' : ''}>\${escapeHtml(m.title)}\${Number(m.is_default) === 1 ? ' ★' : ''}</option>`;
    });
    return html;
}

function killControlsHtml(s) {
    if (!s.can_kill || !s.session_id) return '';
    const body = escapeHtml(defaultStopBody());
    return `<div class="mt-2 kill-message-box">
        <select class="form-select form-select-sm mb-1 kill-preset-select" aria-label="Mensaje predefinido">\${killPresetOptionsHtml()}</select>
        <textarea class="form-control form-control-sm mb-1 kill-message-input" rows="2" placeholder="Mensaje al usuario (opcional)" maxlength="500">\${body}</textarea>
        <button type="button" class="btn btn-outline-danger btn-sm w-100 btn-kill-session" data-server-id="\${s.server_id}" data-session-id="\${escapeHtml(s.session_id)}"><i class="bi bi-stop-circle me-1"></i>Pausar / detener</button>
    </div>`;
}

function streamInfoHtml(s) {
    const info = s.stream_info || {};
    const rows = [
        ['Quality', info.quality],
        ['Stream', info.stream],
        ['Container', info.container],
        ['Video', info.video || s.video_label || s.video_decision],
        ['Audio', info.audio || s.audio_label || s.audio_decision],
        ['Subtitle', info.subtitle || 'None'],
    ];
    const body = rows
        .filter(([, v]) => String(v ?? '').trim() !== '')
        .map(([k, v]) => `<div class="session-stream-row"><dt>\${escapeHtml(k)}</dt><dd title="\${escapeHtml(v)}">\${escapeHtml(v)}</dd></div>`)
        .join('');
    return body ? `<dl class="session-stream-info small mb-2">\${body}</dl>` : '';
}

function overLimitBadgeHtml(s) {
    if (!s.over_limit) return '';
    const count = Number(s.user_stream_count || 0);
    const limit = Number(s.stream_limit || 0);
    return `<div class="mb-2"><span class="badge bg-danger" title="Este usuario supera su límite de streams simultáneos (\${count}/\${limit})"><i class="bi bi-exclamation-octagon me-1"></i>Límite streams \${count}/\${limit}</span></div>`;
}

function sessionCardHtml(s) {
    const method = s.play_method || '';
    const badge = playBadges[method] || 'secondary';
    const label = playLabels[method] || method;
    const progress = Number(s.progress || 0);
    const thumb = thumbHtml(s);
    const killBtn = killControlsHtml(s);
    const streamBlock = streamInfoHtml(s);
    const overLimit = overLimitBadgeHtml(s);

    return `<div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card border-0 shadow-sm h-100 session-card\${s.over_limit ? ' border border-danger' : ''}">
            <div class="session-poster rounded-top">\${thumb}</div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                    <span class="badge bg-\${badge}">\${escapeHtml(label)}</span>
                    <span class="badge bg-secondary">\${escapeHtml((s.server_type || '').toUpperCase())}</span>
                </div>
                \${overLimit}
                <h6 class="card-title mb-1 text-truncate" title="\${escapeHtml(s.title)}">\${escapeHtml(s.title || 'Sin título')}</h6>
                \${s.subtitle ? `<p class="small text-muted mb-2 text-truncate">\${escapeHtml(s.subtitle)}</p>` : ''}
                <p class="small mb-1"><i class="bi bi-person me-1"></i>\${escapeHtml(s.user || '-')}</p>
                <p class="small mb-1"><i class="bi bi-hdd-network me-1"></i>\${escapeHtml(s.server_name || '-')}</p>
                <p class="small mb-2"><i class="bi bi-display me-1"></i>\${escapeHtml(s.player || '-')} \${s.platform ? `<span class="text-muted">(\${escapeHtml(s.platform)})</span>` : ''}</p>
                \${streamBlock}
                <div class="progress mb-1" style="height:4px;"><div class="progress-bar" style="width:\${progress}%"></div></div>
                <div class="d-flex justify-content-between small text-muted"><span>\${escapeHtml(s.state || '')}</span><span>\${progress}%</span></div>
                \${killBtn}
            </div>
        </div>
    </div>`;
}

function emptyHtml(message) {
    return `<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="bi bi-tv fs-1 d-block mb-2"></i>\${escapeHtml(message)}</div></div>`;
}

function updateStats(data) {
    const total = data.total_count ?? 0;
    document.getElementById('session-count').textContent = total + ' streams totales';
    const totalEl = document.querySelector('[data-stat="total"]');
    if (totalEl) totalEl.textContent = total;
    const badgeTotal = document.getElementById('badge-total');
    if (badgeTotal) badgeTotal.textContent = total;

    (data.server_stats || []).forEach(stat => {
        const el = document.querySelector(`[data-stat-server="\${stat.id}"]`);
        if (el) el.textContent = stat.count;
        const badge = document.querySelector(`.badge-server[data-server-id="\${stat.id}"]`);
        if (badge) badge.textContent = stat.count;
    });
}

function renderFlat(sessions) {
    const container = document.getElementById('sessions-container');
    if (!sessions.length) {
        container.innerHTML = `<div class="row g-3" id="sessions-grid"><div class="col-12">\${emptyHtml('No hay reproducciones activas en este servidor')}</div></div>`;
        return;
    }
    container.innerHTML = `<div class="row g-3" id="sessions-grid">\${sessions.map(sessionCardHtml).join('')}</div>`;
}

function renderGrouped(grouped) {
    const container = document.getElementById('sessions-container');
    if (!grouped.length) {
        container.innerHTML = `<div id="sessions-grouped">\${emptyHtml('No hay reproducciones activas')}</div>`;
        return;
    }

    container.innerHTML = `<div id="sessions-grouped">\${grouped.map(group => `
        <div class="mb-4 server-group" data-server-id="\${group.server_id}">
            <div class="d-flex align-items-center gap-2 mb-3">
                <h5 class="mb-0">\${escapeHtml(group.server_name)}</h5>
                <span class="badge bg-\${group.server_type === 'plex' ? 'warning' : 'info'}">\${escapeHtml((group.server_type || '').toUpperCase())}</span>
                <span class="badge bg-primary group-count">\${group.sessions.length} streams</span>
                <a href="/activity?server_id=\${group.server_id}" class="btn btn-sm btn-outline-secondary ms-auto">Ver solo este</a>
            </div>
            <div class="row g-3">\${group.sessions.map(sessionCardHtml).join('')}</div>
        </div>`).join('')}</div>`;
}

async function refreshSessions() {
    try {
        const res = await fetch(apiUrl, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        updateStats(data);
        if (viewMode === 'server') {
            renderFlat(data.sessions || []);
        } else {
            renderGrouped(data.grouped || []);
        }
    } catch (e) {
        console.error('Error refreshing sessions', e);
    }
}

document.getElementById('refresh-btn').addEventListener('click', refreshSessions);
setInterval(refreshSessions, 10000);

document.addEventListener('change', function (e) {
    const select = e.target.closest('.kill-preset-select');
    if (!select) return;
    const box = select.closest('.kill-message-box');
    const textarea = box?.querySelector('.kill-message-input');
    if (!textarea) return;
    if (!select.value) {
        textarea.value = '';
        return;
    }
    const preset = (stopMessages || []).find(m => String(m.id) === String(select.value));
    textarea.value = preset ? String(preset.body || '') : '';
});

document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-kill-session');
    if (!btn) return;
    const card = btn.closest('.session-card') || btn.parentElement;
    const msgInput = card?.querySelector('.kill-message-input');
    const message = (msgInput?.value || '').trim();
    const confirmText = message
        ? '¿Detener esta reproducción y enviar el mensaje al usuario?'
        : '¿Detener esta reproducción?';
    if (!confirm(confirmText)) return;
    btn.disabled = true;
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    if (!csrf) {
        alert('No hay token CSRF. Recarga la página (F5).');
        btn.disabled = false;
        return;
    }
    try {
        const body = new URLSearchParams({
            _token: csrf,
            server_id: btn.dataset.serverId,
            session_id: btn.dataset.sessionId,
            message: message,
        });
        const res = await fetch('/activity/kill', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Csrf-Token': csrf,
            },
            body,
        });
        const data = await res.json().catch(() => ({}));
        if (data.success) {
            btn.closest('.session-card')?.remove();
            refreshSessions();
        } else {
            alert(data.message || 'No se pudo detener');
            btn.disabled = false;
        }
    } catch (err) {
        alert('Error de red');
        btn.disabled = false;
    }
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
