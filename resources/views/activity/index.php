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
const apiUrl = '/activity/api{$serverFilter}';
window.MP_STOP_MESSAGES = {$stopMessagesJson};
const cards = window.MPSessionCards || {};
const sessionCardHtml = cards.sessionCardHtml || (() => '');
const emptyHtml = cards.emptyHtml || ((m) => m);
const escapeHtml = cards.escapeHtml || ((v) => String(v ?? ''));

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
