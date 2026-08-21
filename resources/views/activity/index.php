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
    '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="225" viewBox="0 0 150 225">'
    + '<rect width="150" height="225" fill="#1a1a1a"/>'
    + '<text x="75" y="118" fill="#666" text-anchor="middle" font-family="sans-serif" font-size="12">N/A</text>'
    + '</svg>'
);

function onSessionThumbError(img) {
    img.onerror = null;
    const poster = img.previousElementSibling;
    if (poster && poster.classList.contains('session-poster')) {
        poster.style.backgroundImage = 'url(' + thumbFallbackSrc + ')';
    }
}

function posterSmsBtnHtml(s) {
    if (!s.can_kill || !s.session_id) return '';
    return `<button type="button" class="session-poster-sms" title="Enviar mensaje / detener reproducción" aria-label="Enviar mensaje o detener reproducción" data-server-id="\${Number(s.server_id || 0)}" data-session-id="\${escapeHtml(String(s.session_id))}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;
}

function thumbBlockHtml(s) {
    const url = sessionThumbUrl(s);
    if (!url) {
        return `<div class="session-poster session-poster--fallback"><i class="bi bi-film" aria-hidden="true"></i></div>`;
    }
    return `<div class="session-poster" style="background-image:url('\${escapeHtml(url)}')" role="img" aria-label=""></div>`
        + `<img class="session-poster-img-probe" src="\${escapeHtml(url)}" alt="" decoding="async" onerror="onSessionThumbError(this)">`;
}

function infoItemHtml(label, value, warn) {
    const v = String(value ?? '').trim() || '—';
    return `<li class="session-info-item"><span class="session-info-key">\${escapeHtml(label)}</span><span class="session-info-val\${warn ? ' session-info-val--warn' : ''}">\${escapeHtml(v)}</span></li>`;
}

function streamInfoPanelHtml(s) {
    const info = s.stream_info || {};
    const method = s.play_method || '';
    const isTranscode = method === 'transcode';
    const methodLabel = playLabels[method] || method || 'Direct Play';
    const streamLine = String(info.stream || '').trim() || methodLabel;
    const product = String(s.product || s.platform || '—');
    const player = String(s.player || '—');
    const quality = String(info.quality || '—');
    const container = String(info.container || '—');
    const video = String(info.video || s.video_label || s.video_decision || '—');
    const audio = String(info.audio || s.audio_label || s.audio_decision || '—');
    const subtitle = String(info.subtitle || 'None');
    const location = String(s.location || '').trim();
    const ip = String(s.client_ip || '').trim();
    let locationLine = location || String(s.server_type || '').toUpperCase();
    if (ip) locationLine = (locationLine ? locationLine + ': ' : '') + ip;
    const bandwidth = String(s.bandwidth || '').trim() || String(s.server_name || '—');
    const progress = Math.max(0, Math.min(100, Number(s.progress || 0)));
    const state = String(s.state || '').toLowerCase();
    const cls = 'session-info-panel session-stream-info' + (isTranscode ? ' session-stream-info--transcode expanded' : '');
    const aria = isTranscode ? 'true' : 'false';
    const title = isTranscode ? 'Detalle de Transcode' : 'Clic para ampliar detalle';

    return `<div class="\${cls}" role="button" tabindex="0" aria-expanded="\${aria}" title="\${title}">
        <div class="session-info-scroller">
            <ul class="session-info-list">
                \${infoItemHtml('Product', product)}
                \${infoItemHtml('Player', player)}
                \${infoItemHtml('Quality', quality)}
            </ul>
            <ul class="session-info-list">
                \${infoItemHtml('Stream', streamLine, isTranscode)}
                \${infoItemHtml('Container', container)}
                \${infoItemHtml('Video', video, isTranscode && /transcode/i.test(video))}
                \${infoItemHtml('Audio', audio)}
                \${infoItemHtml('Subtitle', subtitle)}
            </ul>
            <ul class="session-info-list">
                \${infoItemHtml('Dónde', (String(s.household || '') === 'home' ? 'Casa' : 'Fuera') + (locationLine ? ' · ' + locationLine : ''))}
                \${infoItemHtml('Bandwidth', bandwidth)}
            </ul>
        </div>
        <div class="session-info-time">
            \${state ? `<span class="session-state">\${escapeHtml(state)}</span>` : ''}
            <span class="session-pct">\${progress}%</span>
        </div>
    </div>`;
}

function overLimitBadgeHtml(s) {
    if (!s.over_limit && !s.would_cut) return '';
    const away = String(s.cut_reason || '') === 'away';
    const label = away ? 'Otra casa' : 'De más';
    const title = away ? 'Otra casa / fuera' : 'Demasiadas teles en casa';
    return `<span class="badge bg-danger session-limit-badge" title="\${escapeHtml(title)}">\${label}</span>`;
}

function householdBadgeHtml(s) {
    const home = String(s.household || '') === 'home';
    const label = home ? 'Casa' : 'Fuera';
    const cls = home ? 'bg-success' : 'bg-warning text-dark';
    const src = String(s.household_source || '');
    let title = home ? 'Casa' : 'Fuera';
    if (src === 'device_tv') title = 'Tele / Fire Stick';
    else if (src === 'device_mobile') title = 'Móvil / tablet';
    else if (src === 'lan') title = 'Misma red que el servidor';
    else if (src === 'home_ip') title = 'IP marcada como hogar';
    return `<span class="badge session-household-badge \${cls}" title="\${escapeHtml(title)}">\${label}</span>`;
}

function stateIconClass(state) {
    switch (String(state || '').toLowerCase()) {
        case 'paused': return 'bi-pause-fill';
        case 'buffering': return 'bi-arrow-repeat';
        case 'error': return 'bi-exclamation-triangle-fill';
        default: return 'bi-play-fill';
    }
}

function mediaIconClass(mediaType) {
    const t = String(mediaType || '').toLowerCase();
    if (['episode', 'show', 'series'].includes(t)) return 'bi-tv';
    if (['track', 'audio', 'music'].includes(t)) return 'bi-music-note-beamed';
    if (t === 'photo') return 'bi-image';
    return 'bi-film';
}

function sessionUserHtml(s) {
    const name = escapeHtml(s.user || '-');
    const uuid = String(s.media_user_uuid || '').trim();
    const badge = householdBadgeHtml(s);
    if (uuid) {
        return `\${badge} <a href="/media-users/\${encodeURIComponent(uuid)}" class="session-user-link text-decoration-none">\${name}</a>`;
    }
    return `\${badge} <span class="session-user-name">\${name}</span>`;
}

function sessionCardHtml(s) {
    const method = s.play_method || '';
    const progress = Math.max(0, Math.min(100, Number(s.progress || 0)));
    const isTranscode = method === 'transcode';
    const thumb = thumbBlockHtml(s);
    const streamPanel = streamInfoPanelHtml(s);
    const overLimit = overLimitBadgeHtml(s);
    const title = escapeHtml(s.title || 'Sin título');
    const sid = escapeHtml(String(s.session_id || ''));
    const state = String(s.state || '');
    const sms = posterSmsBtnHtml(s);
    const platformLabel = String(s.platform || s.product || 'Plex');
    const platformInitial = escapeHtml(platformLabel.charAt(0).toUpperCase() || 'P');
    const platformShort = escapeHtml(platformLabel.length > 10 ? platformLabel.slice(0, 9) + '…' : platformLabel);
    const subtitle = String(s.subtitle || '').trim() || String(s.year || '').trim() || String(s.server_name || '');
    const bgUrl = sessionThumbUrl(s);
    const bgStyle = bgUrl ? `background-image:url('\${escapeHtml(bgUrl)}')` : '';
    const bufferPct = Math.min(100, progress + (isTranscode ? 8 : 0));

    return `<div class="col-12 col-lg-6 col-xxl-4 session-col">
        <div class="session-card session-row\${s.over_limit ? ' session-row--over-limit' : ''}\${isTranscode ? ' session-row--transcode' : ''}" data-session-id="\${sid}" data-server-id="\${Number(s.server_id || 0)}" data-play-method="\${escapeHtml(method)}">
            <div class="session-activity-container">
                <div class="session-activity-background" style="\${bgStyle}">
                    <div class="session-poster-wrap">\${thumb}</div>
                    <div class="session-platform-slot\${s.can_kill ? '' : ' session-platform-slot--no-terminate'}">
                        <div class="session-platform-badge" title="\${escapeHtml(platformLabel)}">
                            <span class="session-platform-initial">\${platformInitial}</span>
                            <span class="session-platform-name">\${platformShort}</span>
                        </div>
                        \${sms}
                    </div>
                    \${streamPanel}
                </div>
                <div class="session-activity-progress" title="\${progress}%">
                    <div class="session-activity-progress-track">
                        \${isTranscode ? `<div class="session-buffer-bar" style="width:\${bufferPct}%">\${bufferPct}%</div>` : ''}
                        <div class="session-progress-bar\${isTranscode ? ' session-progress-bar--transcode' : ''}" style="width:\${progress}%">\${progress}%</div>
                    </div>
                </div>
            </div>
            <div class="session-metadata">
                <div class="session-meta-title-row">
                    <span class="session-state-icon" title="\${escapeHtml(state || 'playing')}"><i class="bi \${stateIconClass(state)}" aria-hidden="true"></i></span>
                    <h6 class="session-title text-truncate mb-0" role="button" tabindex="0" aria-expanded="false" title="\${title} — clic para ver completo">\${title}</h6>
                    \${overLimit}
                </div>
                <div class="session-meta-sub-row">
                    <span class="session-media-icon" title="\${escapeHtml(String(s.media_type || 'media'))}"><i class="bi \${mediaIconClass(s.media_type)}" aria-hidden="true"></i></span>
                    <span class="session-subtitle text-truncate">\${escapeHtml(subtitle)}</span>
                    <span class="session-meta-user">\${sessionUserHtml(s)}</span>
                </div>
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
        restoreSessionUiState();
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
