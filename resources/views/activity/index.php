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
    <div class="row g-2 g-xl-3" id="sessions-grid">
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
        <div class="mb-3 server-group" data-server-id="<?= (int) $group['server_id'] ?>">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h5 class="mb-0 fs-6"><?= e($group['server_name']) ?></h5>
                <span class="badge bg-<?= $group['server_type'] === 'plex' ? 'warning' : 'info' ?>"><?= e(strtoupper($group['server_type'])) ?></span>
                <span class="badge bg-primary group-count"><?= count($group['sessions']) ?> streams</span>
                <a href="<?= e($buildFilterUrl((int) $group['server_id'])) ?>" class="btn btn-sm btn-outline-secondary ms-auto">Ver solo este</a>
            </div>
            <div class="row g-2 g-xl-3">
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
window.MP_STOP_MESSAGES = {$stopMessagesJson};
const stopMessages = window.MP_STOP_MESSAGES;

/** Estado UI por session_id: sobrevive al rebuild del polling */
const sessionUiState = new Map();

function captureSessionUiState() {
    document.querySelectorAll('.session-card[data-session-id]').forEach(card => {
        const id = String(card.dataset.sessionId || '');
        if (!id) return;
        const titleEl = card.querySelector('.session-title');
        const infoEl = card.querySelector('.session-stream-info');
        sessionUiState.set(id, {
            titleExpanded: !!(titleEl && titleEl.classList.contains('expanded')),
            streamExpanded: !!(infoEl && infoEl.classList.contains('expanded')),
        });
    });
}

function restoreSessionUiState() {
    document.querySelectorAll('.session-card[data-session-id]').forEach(card => {
        const id = String(card.dataset.sessionId || '');
        const state = sessionUiState.get(id);
        if (!state) return;
        const titleEl = card.querySelector('.session-title');
        if (titleEl && state.titleExpanded) {
            titleEl.classList.add('expanded');
            titleEl.classList.remove('text-truncate');
            titleEl.setAttribute('aria-expanded', 'true');
        }
        const infoEl = card.querySelector('.session-stream-info');
        if (infoEl && state.streamExpanded) {
            infoEl.classList.add('expanded');
            infoEl.setAttribute('aria-expanded', 'true');
            const toggle = infoEl.querySelector('.stream-info-toggle');
            if (toggle) toggle.textContent = 'Ver menos';
        }
    });
}

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
    '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="120" viewBox="0 0 80 120">'
    + '<rect width="80" height="120" fill="#2b2f36"/>'
    + '<text x="40" y="64" fill="#9aa0a6" text-anchor="middle" font-family="sans-serif" font-size="9">N/A</text>'
    + '</svg>'
);

function onSessionThumbError(img) {
    img.onerror = null;
    img.src = thumbFallbackSrc;
}

function posterSmsBtnHtml(s) {
    if (!s.can_kill || !s.session_id) return '';
    return `<button type="button" class="session-poster-sms" title="Enviar mensaje / detener reproducción" aria-label="Enviar mensaje o detener reproducción" data-server-id="\${Number(s.server_id || 0)}" data-session-id="\${escapeHtml(String(s.session_id))}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;
}

function thumbHtml(s) {
    const url = sessionThumbUrl(s);
    if (!url) {
        return `<div class="session-poster-fallback"><i class="bi bi-film"></i></div>`;
    }
    return `<img src="\${escapeHtml(url)}" alt="" decoding="async" onerror="onSessionThumbError(this)">`;
}

function streamInfoHtml(s) {
    const info = s.stream_info || {};
    const method = s.play_method || '';
    const isTranscode = method === 'transcode';
    const methodLabel = playLabels[method] || method;
    const rows = [
        ['Q', 'Quality', info.quality],
        ['S', 'Stream', info.stream],
        ['C', 'Container', info.container],
        ['V', 'Video', info.video || s.video_label || s.video_decision],
        ['A', 'Audio', info.audio || s.audio_label || s.audio_decision],
        ['Sub', 'Subtitle', info.subtitle || 'None'],
    ];
    const detail = rows
        .filter(([, , v]) => String(v ?? '').trim() !== '')
        .map(([short, full, v]) => `<div class="session-stream-row"><span class="session-stream-key" title="\${escapeHtml(full)}">\${escapeHtml(short)}</span><span class="session-stream-val" title="\${escapeHtml(v)}">\${escapeHtml(v)}</span></div>`)
        .join('');
    const summaryParts = [
        (info.stream && String(info.stream).trim()) || methodLabel,
        info.quality,
        info.video || s.video_label || s.video_decision,
        info.audio || s.audio_label || s.audio_decision,
        info.subtitle,
    ].filter(v => {
        const t = String(v ?? '').trim();
        return t !== '' && t !== '—';
    });
    const summary = summaryParts.map(escapeHtml).join(' · ');
    if (!detail && !summary) return '';
    const cls = 'session-stream-info' + (isTranscode ? ' session-stream-info--transcode expanded' : '');
    const aria = isTranscode ? 'true' : 'false';
    const title = isTranscode ? 'Detalle de Transcode' : 'Clic para ver el detalle completo';
    const toggle = isTranscode ? '' : '<span class="stream-info-toggle" aria-hidden="true">Ver más</span>';
    const summaryHtml = summary
        ? `<div class="session-stream-summary" title="\${summary}">\${summary}</div>`
        : '';
    return `<div class="\${cls}" role="button" tabindex="0" aria-expanded="\${aria}" title="\${title}">\${summaryHtml}<div class="session-stream-detail">\${detail}</div>\${toggle}</div>`;
}

function overLimitBadgeHtml(s) {
    if (!s.over_limit) return '';
    const count = Number(s.user_stream_count || 0);
    const limit = Number(s.stream_limit || 0);
    return `<span class="badge bg-danger session-limit-badge" title="Supera el límite (IPs/sesiones: \${count}/\${limit})">Límite \${count}/\${limit}</span>`;
}

function sessionUserDeviceHtml(s) {
    const name = escapeHtml(s.user || '-');
    const uuid = String(s.media_user_uuid || '').trim();
    const player = escapeHtml(s.player || '-');
    const platform = s.platform
        ? ` <span class="session-meta-platform">(\${escapeHtml(s.platform)})</span>`
        : '';
    const userPart = uuid
        ? `<a href="/media-users/\${encodeURIComponent(uuid)}" class="session-user-link text-decoration-none">\${name}</a>`
        : `<span class="session-user-name">\${name}</span>`;
    return `\${userPart}<span class="session-meta-sep">·</span><span class="session-meta-device">\${player}\${platform}</span>`;
}

function sessionFootHtml(s) {
    const parts = [];
    if (s.client_ip) {
        parts.push(`<code class="session-ip">\${escapeHtml(s.client_ip)}</code>`);
    }
    parts.push(`<span class="session-server">\${escapeHtml(s.server_name || '-')}</span>`);
    if (s.server_type) {
        parts.push(`<span class="session-server-type">\${escapeHtml(String(s.server_type).toUpperCase())}</span>`);
    }
    return parts.join('<span class="session-meta-sep">·</span>');
}

function sessionCardHtml(s) {
    const method = s.play_method || '';
    const badge = playBadges[method] || 'secondary';
    const label = playLabels[method] || method;
    const progress = Number(s.progress || 0);
    const isTranscode = method === 'transcode';
    const thumb = thumbHtml(s);
    const streamBlock = streamInfoHtml(s);
    const overLimit = overLimitBadgeHtml(s);
    const title = escapeHtml(s.title || 'Sin título');
    const sid = escapeHtml(String(s.session_id || ''));
    const state = String(s.state || '');
    const progressMeta = state
        ? `<span class="session-state">\${escapeHtml(state)}</span><span class="session-meta-sep">·</span><span class="session-pct">\${progress}%</span>`
        : `<span class="session-pct">\${progress}%</span>`;
    const sms = posterSmsBtnHtml(s);

    return `<div class="col-12 col-sm-6 col-lg-4 col-xl-3 session-col">
        <div class="session-card session-row\${s.over_limit ? ' session-row--over-limit' : ''}" data-session-id="\${sid}" data-server-id="\${Number(s.server_id || 0)}" data-play-method="\${escapeHtml(method)}">
            <div class="session-poster">\${thumb}</div>
            <div class="session-main">
                <div class="session-head">
                    <p class="session-meta-line mb-0">\${sessionUserDeviceHtml(s)}\${overLimit}</p>
                    <div class="session-head-actions">
                        <span class="badge bg-\${badge} session-method-badge">\${escapeHtml(label)}</span>
                        \${sms}
                    </div>
                </div>
                <h6 class="session-title text-truncate mb-0" role="button" tabindex="0" aria-expanded="false" title="\${title} — clic para ver completo">\${title}</h6>
                \${s.subtitle ? `<p class="session-subtitle text-truncate mb-0">\${escapeHtml(s.subtitle)}</p>` : ''}
                <div class="session-progress-row">
                    <div class="progress session-progress"><div class="progress-bar\${isTranscode ? ' session-progress-bar--transcode' : ''}" style="width:\${progress}%"></div></div>
                    <span class="session-progress-meta">\${progressMeta}</span>
                </div>
                \${streamBlock}
                <p class="session-foot mb-0">\${sessionFootHtml(s)}</p>
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
    captureSessionUiState();
    if (!sessions.length) {
        container.innerHTML = `<div class="row g-2 g-xl-3" id="sessions-grid"><div class="col-12">\${emptyHtml('No hay reproducciones activas en este servidor')}</div></div>`;
        return;
    }
    container.innerHTML = `<div class="row g-2 g-xl-3" id="sessions-grid">\${sessions.map(sessionCardHtml).join('')}</div>`;
    restoreSessionUiState();
}

function renderGrouped(grouped) {
    const container = document.getElementById('sessions-container');
    captureSessionUiState();
    if (!grouped.length) {
        container.innerHTML = `<div id="sessions-grouped">\${emptyHtml('No hay reproducciones activas')}</div>`;
        return;
    }

    container.innerHTML = `<div id="sessions-grouped">\${grouped.map(group => `
        <div class="mb-3 server-group" data-server-id="\${group.server_id}">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h5 class="mb-0 fs-6">\${escapeHtml(group.server_name)}</h5>
                <span class="badge bg-\${group.server_type === 'plex' ? 'warning' : 'info'}">\${escapeHtml((group.server_type || '').toUpperCase())}</span>
                <span class="badge bg-primary group-count">\${group.sessions.length} streams</span>
                <a href="/activity?server_id=\${group.server_id}" class="btn btn-sm btn-outline-secondary ms-auto">Ver solo este</a>
            </div>
            <div class="row g-2 g-xl-3">\${group.sessions.map(sessionCardHtml).join('')}</div>
        </div>`).join('')}</div>`;
    restoreSessionUiState();
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

window.MP_REFRESH_SESSIONS = refreshSessions;
</script>
JS;
include base_path('resources/views/layouts/app.php');
