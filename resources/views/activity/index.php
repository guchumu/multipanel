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
    $params = [];
    if ($serverId !== null) {
        $params['server_id'] = $serverId;
    }
    $query = $params !== [] ? '?' . http_build_query($params) : '';
    return '/activity' . $query;
};

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">En directo</h4>
        <small class="text-muted">Reproducciones activas en tus servidores</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary" id="session-count"><?= count($sessions) ?> sesiones</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="refresh-btn"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <span class="small text-muted me-1">Servidor:</span>
        <div class="btn-group btn-group-sm flex-wrap">
            <a href="<?= e($buildFilterUrl(null)) ?>" class="btn btn-outline-secondary <?= !$currentServerId ? 'active' : '' ?>">Todos</a>
            <?php foreach ($servers as $server): ?>
            <a href="<?= e($buildFilterUrl((int) $server->id)) ?>"
               class="btn btn-outline-secondary <?= $currentServerId === (int) $server->id ? 'active' : '' ?>">
                <?= e($server->name) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-3" id="sessions-grid">
    <?php if (empty($sessions)): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-tv fs-1 d-block mb-2"></i>
                No hay reproducciones activas
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($sessions as $session): ?>
    <div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card border-0 shadow-sm h-100 session-card">
            <div class="ratio ratio-2x3 bg-dark rounded-top overflow-hidden">
                <?php if (!empty($session['thumb_url'])): ?>
                <img src="<?= e($session['thumb_url']) ?>" alt="" class="object-fit-cover" loading="lazy"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="d-none align-items-center justify-content-center h-100 text-white-50">
                    <i class="bi bi-film fs-1"></i>
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center h-100 text-white-50">
                    <i class="bi bi-film fs-1"></i>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <span class="badge bg-<?= $playMethodBadge($session['play_method'] ?? '') ?>">
                        <?= e($playMethodLabel($session['play_method'] ?? '')) ?>
                    </span>
                    <span class="badge bg-secondary"><?= e(strtoupper($session['server_type'] ?? '')) ?></span>
                </div>
                <h6 class="card-title mb-1 text-truncate" title="<?= e($session['title'] ?? '') ?>">
                    <?= e($session['title'] ?? 'Sin título') ?>
                </h6>
                <?php if (!empty($session['subtitle'])): ?>
                <p class="small text-muted mb-2 text-truncate"><?= e($session['subtitle']) ?></p>
                <?php endif; ?>
                <p class="small mb-1"><i class="bi bi-person me-1"></i><?= e($session['user'] ?? '-') ?></p>
                <p class="small mb-1"><i class="bi bi-hdd-network me-1"></i><?= e($session['server_name'] ?? '-') ?></p>
                <p class="small mb-2"><i class="bi bi-display me-1"></i><?= e($session['player'] ?? '-') ?>
                    <?php if (!empty($session['platform'])): ?>
                    <span class="text-muted">(<?= e($session['platform']) ?>)</span>
                    <?php endif; ?>
                </p>
                <?php $progress = (int) ($session['progress'] ?? 0); ?>
                <div class="progress mb-1" style="height: 4px;">
                    <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?= e($session['state'] ?? '') ?></span>
                    <span><?= $progress ?>%</span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
$serverFilter = $currentServerId ? '?server_id=' . (int) $currentServerId : '';
$scripts = <<<JS
<script>
const playLabels = { direct_play: 'Direct Play', direct_stream: 'Direct Stream', transcode: 'Transcode' };
const playBadges = { direct_play: 'success', direct_stream: 'info', transcode: 'warning' };
const apiUrl = '/activity/api{$serverFilter}';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function renderSessions(sessions) {
    const grid = document.getElementById('sessions-grid');
    const count = document.getElementById('session-count');
    count.textContent = sessions.length + ' sesiones';

    if (!sessions.length) {
        grid.innerHTML = '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="bi bi-tv fs-1 d-block mb-2"></i>No hay reproducciones activas</div></div></div>';
        return;
    }

    grid.innerHTML = sessions.map(s => {
        const method = s.play_method || '';
        const badge = playBadges[method] || 'secondary';
        const label = playLabels[method] || method;
        const progress = Number(s.progress || 0);
        const thumb = s.thumb_url
            ? `<img src="\${escapeHtml(s.thumb_url)}" alt="" class="object-fit-cover" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"><div class="d-none align-items-center justify-content-center h-100 text-white-50"><i class="bi bi-film fs-1"></i></div>`
            : '<div class="d-flex align-items-center justify-content-center h-100 text-white-50"><i class="bi bi-film fs-1"></i></div>';

        return `<div class="col-sm-6 col-lg-4 col-xl-3">
            <div class="card border-0 shadow-sm h-100 session-card">
                <div class="ratio ratio-2x3 bg-dark rounded-top overflow-hidden">\${thumb}</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <span class="badge bg-\${badge}">\${escapeHtml(label)}</span>
                        <span class="badge bg-secondary">\${escapeHtml((s.server_type || '').toUpperCase())}</span>
                    </div>
                    <h6 class="card-title mb-1 text-truncate" title="\${escapeHtml(s.title)}">\${escapeHtml(s.title || 'Sin título')}</h6>
                    \${s.subtitle ? `<p class="small text-muted mb-2 text-truncate">\${escapeHtml(s.subtitle)}</p>` : ''}
                    <p class="small mb-1"><i class="bi bi-person me-1"></i>\${escapeHtml(s.user || '-')}</p>
                    <p class="small mb-1"><i class="bi bi-hdd-network me-1"></i>\${escapeHtml(s.server_name || '-')}</p>
                    <p class="small mb-2"><i class="bi bi-display me-1"></i>\${escapeHtml(s.player || '-')} \${s.platform ? `<span class="text-muted">(\${escapeHtml(s.platform)})</span>` : ''}</p>
                    <div class="progress mb-1" style="height:4px;"><div class="progress-bar" style="width:\${progress}%"></div></div>
                    <div class="d-flex justify-content-between small text-muted"><span>\${escapeHtml(s.state || '')}</span><span>\${progress}%</span></div>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function refreshSessions() {
    try {
        const res = await fetch(apiUrl, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        renderSessions(data.sessions || []);
    } catch (e) {
        console.error('Error refreshing sessions', e);
    }
}

document.getElementById('refresh-btn').addEventListener('click', refreshSessions);
setInterval(refreshSessions, 10000);
</script>
JS;
include base_path('resources/views/layouts/app.php');
