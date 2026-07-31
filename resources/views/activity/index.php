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

$renderSessionCard = static function (array $session) use ($playMethodLabel, $playMethodBadge): void {
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
$scripts = <<<JS
<script>
const viewMode = '{$viewMode}';
const playLabels = { direct_play: 'Direct Play', direct_stream: 'Direct Stream', transcode: 'Transcode' };
const playBadges = { direct_play: 'success', direct_stream: 'info', transcode: 'warning' };
const apiUrl = '/activity/api{$serverFilter}';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function sessionCardHtml(s) {
    const method = s.play_method || '';
    const badge = playBadges[method] || 'secondary';
    const label = playLabels[method] || method;
    const progress = Number(s.progress || 0);
    const thumb = s.thumb_url
        ? `<img src="\${escapeHtml(s.thumb_url)}" alt="" class="object-fit-cover w-100 h-100" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"><div class="d-none align-items-center justify-content-center h-100 text-white-50 position-absolute top-0 start-0 w-100"><i class="bi bi-film fs-1"></i></div>`
        : '<div class="d-flex align-items-center justify-content-center h-100 text-white-50"><i class="bi bi-film fs-1"></i></div>';
    const killBtn = s.can_kill && s.session_id
        ? `<button type="button" class="btn btn-outline-danger btn-sm w-100 mt-2 btn-kill-session" data-server-id="\${s.server_id}" data-session-id="\${escapeHtml(s.session_id)}"><i class="bi bi-stop-circle me-1"></i>Detener reproducción</button>`
        : '';

    return `<div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card border-0 shadow-sm h-100 session-card">
            <div class="ratio ratio-2x3 bg-dark rounded-top overflow-hidden position-relative">\${thumb}</div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                    <span class="badge bg-\${badge}">\${escapeHtml(label)}</span>
                    <span class="badge bg-secondary">\${escapeHtml((s.server_type || '').toUpperCase())}</span>
                </div>
                <h6 class="card-title mb-1 text-truncate" title="\${escapeHtml(s.title)}">\${escapeHtml(s.title || 'Sin título')}</h6>
                \${s.subtitle ? `<p class="small text-muted mb-2 text-truncate">\${escapeHtml(s.subtitle)}</p>` : ''}
                <p class="small mb-1"><i class="bi bi-person me-1"></i>\${escapeHtml(s.user || '-')}</p>
                <p class="small mb-1"><i class="bi bi-hdd-network me-1"></i>\${escapeHtml(s.server_name || '-')}</p>
                <p class="small mb-2"><i class="bi bi-display me-1"></i>\${escapeHtml(s.player || '-')} \${s.platform ? `<span class="text-muted">(\${escapeHtml(s.platform)})</span>` : ''}</p>
                <div class="small mb-2 text-muted">
                    <span class="me-2"><i class="bi bi-camera-video me-1"></i>Vídeo: <strong>\${escapeHtml(s.video_label || s.video_decision || '-')}</strong></span>
                    <span><i class="bi bi-music-note-beamed me-1"></i>Audio: <strong>\${escapeHtml(s.audio_label || s.audio_decision || '-')}</strong></span>
                </div>
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

document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-kill-session');
    if (!btn) return;
    if (!confirm('¿Detener esta reproducción?')) return;
    btn.disabled = true;
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    try {
        const body = new URLSearchParams({
            _token: csrf,
            server_id: btn.dataset.serverId,
            session_id: btn.dataset.sessionId,
        });
        const res = await fetch('/activity/kill', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();
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
