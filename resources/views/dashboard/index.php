<?php
ob_start();
?>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small"><?= __('users_active') ?></p>
                        <h3 class="mb-0" data-live="users-active"><?= (int) $stats['users_active'] ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 rounded p-2">
                        <i class="bi bi-person-check text-success fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small"><?= __('users_suspended') ?></p>
                        <h3 class="mb-0"><?= (int) $stats['users_suspended'] ?></h3>
                    </div>
                    <div class="bg-warning bg-opacity-10 rounded p-2">
                        <i class="bi bi-person-x text-warning fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small"><?= __('servers_online') ?></p>
                        <h3 class="mb-0"><?= (int) $stats['servers_online'] ?>/<?= (int) $stats['servers_total'] ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 rounded p-2">
                        <i class="bi bi-hdd-network text-primary fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small"><?= __('users_total') ?></p>
                        <h3 class="mb-0"><?= (int) $stats['users_total'] ?></h3>
                    </div>
                    <div class="bg-info bg-opacity-10 rounded p-2">
                        <i class="bi bi-people text-info fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$initialStreams = 0;
foreach ($servers as $s) {
    $initialStreams += (int) ($s->active_sessions ?? 0);
}
?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <button type="button"
                class="card border-0 shadow-sm w-100 text-start btn p-0 overflow-hidden"
                id="live-activity-card"
                data-bs-toggle="modal"
                data-bs-target="#liveActivityModal"
                title="Ver reproducciones en curso">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-danger bg-opacity-10 rounded p-2">
                            <i class="bi bi-broadcast-pin text-danger fs-3"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-1 small">Actividad en directo</p>
                            <div class="d-flex flex-wrap align-items-baseline gap-3">
                                <h3 class="mb-0">
                                    <span id="dash-live-streams"><?= (int) $initialStreams ?></span>
                                    <span class="fs-6 fw-normal text-muted">streams</span>
                                </h3>
                                <h3 class="mb-0">
                                    <span id="dash-live-transcodes">—</span>
                                    <span class="fs-6 fw-normal text-muted">transcodes</span>
                                </h3>
                            </div>
                            <small class="text-muted" id="dash-live-hint">Clic para ver detalle por servidor · actualiza cada 20s</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary" id="dash-live-refresh">…</span>
                        <span class="text-primary small fw-medium">Ver en vivo <i class="bi bi-chevron-right"></i></span>
                    </div>
                </div>
            </div>
        </button>
    </div>
</div>

<?php
$renewalOutlook = $renewalOutlook ?? [
    'this_month' => ['label' => 'Este mes', 'caducidades' => 0],
    'next_month' => ['label' => 'Próximo mes', 'caducidades' => 0],
];
$thisMonthLabel = (string) ($renewalOutlook['this_month']['label'] ?? 'Este mes');
$nextMonthLabel = (string) ($renewalOutlook['next_month']['label'] ?? 'Próximo mes');
$thisMonthCount = (int) ($renewalOutlook['this_month']['caducidades'] ?? 0);
$nextMonthCount = (int) ($renewalOutlook['next_month']['caducidades'] ?? 0);
?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <a href="/media-users/estimacion" class="card border-0 shadow-sm text-decoration-none text-body d-block">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <div class="bg-warning bg-opacity-10 rounded p-2 flex-shrink-0">
                            <i class="bi bi-calendar3 text-warning fs-4"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-muted mb-1 small">Caducidades previstas</p>
                            <div class="d-flex flex-wrap align-items-baseline gap-3 gap-md-4">
                                <div>
                                    <span class="fs-4 fw-semibold"><?= $thisMonthCount ?></span>
                                    <span class="text-muted small ms-1"><?= e($thisMonthLabel) ?></span>
                                </div>
                                <div class="vr d-none d-sm-block"></div>
                                <div>
                                    <span class="fs-4 fw-semibold"><?= $nextMonthCount ?></span>
                                    <span class="text-muted small ms-1"><?= e($nextMonthLabel) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <span class="text-primary small fw-medium flex-shrink-0">
                        Ver estimación mensual <i class="bi bi-chevron-right"></i>
                    </span>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="modal fade" id="liveActivityModal" tabindex="-1" aria-labelledby="liveActivityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="liveActivityModalLabel">
                        <i class="bi bi-broadcast-pin me-1 text-danger"></i>Reproducciones en curso
                    </h5>
                    <small class="text-muted" id="live-modal-updated">Actualizando…</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3" id="live-modal-summary">
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 h-100">
                            <div class="small text-muted">Streams online</div>
                            <div class="fs-4 fw-semibold" id="live-modal-streams">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 h-100">
                            <div class="small text-muted">Transcodes</div>
                            <div class="fs-4 fw-semibold text-warning" id="live-modal-transcodes">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 h-100">
                            <div class="small text-muted">Direct Play</div>
                            <div class="fs-4 fw-semibold text-success" id="live-modal-direct">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 h-100">
                            <div class="small text-muted">Direct Stream</div>
                            <div class="fs-4 fw-semibold text-info" id="live-modal-dstream">0</div>
                        </div>
                    </div>
                </div>

                <h6 class="mb-2">Por servidor</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Servidor</th>
                                <th>Tipo</th>
                                <th class="text-end">Streams</th>
                                <th class="text-end">Transcodes</th>
                            </tr>
                        </thead>
                        <tbody id="live-modal-servers">
                            <tr><td colspan="4" class="text-muted text-center py-3">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>

                <h6 class="mb-2">Reproducciones actuales</h6>
                <div id="live-modal-sessions" class="list-group list-group-flush border rounded">
                    <div class="list-group-item text-muted text-center py-3">Cargando…</div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <small class="text-muted">Datos del snapshot en vivo (mismo que En directo)</small>
                <a href="/activity" class="btn btn-primary btn-sm">
                    <i class="bi bi-broadcast-pin me-1"></i>Ir a En directo
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><?= __('server_status') ?></h6>
                <small id="live-indicator" class="text-muted">● <?= __('connecting') ?></small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Servidor</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                                <th>Sesiones</th>
                                <th>Última sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($servers)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No hay servidores registrados</td></tr>
                            <?php else: ?>
                            <?php foreach ($servers as $server): ?>
                            <tr>
                                <td><a href="/servers/<?= e($server->uuid) ?>"><?= e($server->name) ?></a></td>
                                <td><span class="badge bg-secondary"><?= e(strtoupper($server->type)) ?></span></td>
                                <td>
                                    <?php
                                    $badge = match ($server->status) {
                                        'online' => 'success',
                                        'offline' => 'danger',
                                        'syncing' => 'info',
                                        default => 'warning',
                                    };
                                    ?>
                                    <span class="badge bg-<?= $badge ?>"><?= e($server->status) ?></span>
                                </td>
                                <td data-live="sessions"><?= (int) $server->active_sessions ?></td>
                                <td class="text-muted small"><?= e($server->last_sync_at ?? 'Nunca') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><?= __('user_distribution') ?></h6>
            </div>
            <div class="card-body">
                <canvas id="usersChart" height="200"></canvas>
                <p id="usersChartEmpty" class="text-muted text-center small mb-0 d-none">Todavía no hay usuarios para mostrar.</p>
                <p id="usersChartError" class="text-danger text-center small mb-0 d-none">No se pudo cargar el gráfico (revisa la consola del navegador).</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-envelope-plus me-1"></i>Invitación rápida</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="/dashboard/quick-invite" class="row g-3 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-5">
                        <label class="form-label small mb-1">Email</label>
                        <input type="email" name="email" class="form-control" required placeholder="usuario@ejemplo.com" autocomplete="email">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Días</label>
                        <input type="number" name="days" class="form-control" value="30" min="1" max="3650" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Servidor</label>
                        <select name="server_id" class="form-select" required>
                            <?php
                            $preferredServerId = isset($preferredServerId) ? (int) $preferredServerId : 0;
                            foreach ($servers as $server):
                            ?>
                            <option value="<?= (int) $server->id ?>"
                                <?= ($preferredServerId > 0 && (int) $server->id === $preferredServerId) ? 'selected' : '' ?>>
                                <?= e($server->name) ?> (<?= e(strtoupper($server->type)) ?>)<?= $server->isDefault() ? ' ★' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100" <?= empty($servers) ? 'disabled' : '' ?>>
                            <i class="bi bi-send me-1"></i>Invitar
                        </button>
                    </div>
                </form>
                <p class="small text-muted mb-0 mt-2">
                    En <strong>Plex</strong> envía la invitación por email. En <strong>Jellyfin</strong> genera usuario y contraseña,
                    crea la cuenta en el servidor y te muestra las credenciales para copiar/enviar.
                    El servidor ★ predeterminado se selecciona automáticamente.
                </p>
                <?php if (empty($servers)): ?>
                <div class="alert alert-warning mt-3 mb-0 py-2 small">
                    No hay servidores configurados. Añade uno en <a href="/servers">Servidores</a> antes de invitar.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<?php
$content = ob_get_clean();

// IMPORTANTE: nunca metas tags PHP de eco corto dentro de un heredoc/nowdoc, ni
// escribas un cierre de PHP dentro de un comentario como este: PHP lo honra
// incluso dentro de comentarios de una línea y saca el resto del archivo como
// texto plano al navegador. Pre-codifica los datos con json_encode e interpólalos.
// En heredoc sin comillas, los template literals JS `${...}` se interpretan como
// PHP: escríbelos como \${...} (igual que en activity/index.php).
$usersChartData = json_encode([
    (int) $stats['users_active'],
    (int) $stats['users_suspended'],
    (int) $stats['users_pending'],
    (int) $stats['users_invited'],
    (int) $stats['users_expired'],
]);

$scripts = <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    try {
        const ctx = document.getElementById('usersChart');
        const allData = {$usersChartData};
        const allLabels = ['Activos', 'Suspendidos', 'Pendientes', 'Invitados', 'Caducados'];
        const allColors = ['#198754', '#ffc107', '#6c757d', '#0dcaf0', '#dc3545'];

        // Filtramos las categorías en 0 para que la leyenda no se llene de
        // etiquetas vacías y el donut siempre muestre algo si hay datos.
        const labels = [];
        const data = [];
        const backgroundColor = [];
        allData.forEach((value, i) => {
            if (value > 0) {
                labels.push(allLabels[i]);
                data.push(value);
                backgroundColor.push(allColors[i]);
            }
        });

        if (typeof Chart === 'undefined') {
            console.error('Chart.js no se cargó (¿bloqueado por red/adblock?). No se puede pintar "Distribución de usuarios".');
            document.getElementById('usersChartError')?.classList.remove('d-none');
        } else if (ctx && data.length > 0) {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{ data: data, backgroundColor: backgroundColor }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
            });
        } else if (ctx) {
            document.getElementById('usersChartEmpty')?.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Error al pintar el gráfico de distribución de usuarios:', err);
        document.getElementById('usersChartError')?.classList.remove('d-none');
    }

    try {
        if (window.MultiPanelRealtime) {
            window.MultiPanelRealtime.connect();
        }
    } catch (err) {
        console.error('Error al conectar realtime:', err);
    }

    // Tarjeta / modal de actividad en directo (reutiliza /activity/api + summary)
    (function initLiveActivityCard() {
        const playLabels = { direct_play: 'Direct Play', direct_stream: 'Direct Stream', transcode: 'Transcode' };
        const playBadges = { direct_play: 'success', direct_stream: 'info', transcode: 'warning' };
        const REFRESH_MS = 20000;
        let timer = null;
        let lastPayload = null;

        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[c]);
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        }

        function renderSummary(summary, sessions) {
            const streams = summary?.total_streams ?? sessions?.length ?? 0;
            const transcodes = summary?.total_transcodes ?? 0;
            setText('dash-live-streams', String(streams));
            setText('dash-live-transcodes', String(transcodes));
            setText('live-modal-streams', String(streams));
            setText('live-modal-transcodes', String(transcodes));
            setText('live-modal-direct', String(summary?.total_direct_play ?? 0));
            setText('live-modal-dstream', String(summary?.total_direct_stream ?? 0));

            const tbody = document.getElementById('live-modal-servers');
            const byServer = Array.isArray(summary?.by_server) ? summary.by_server : [];
            if (tbody) {
                if (byServer.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3">Sin servidores</td></tr>';
                } else {
                    tbody.innerHTML = byServer.map(s => `
                        <tr>
                            <td class="fw-medium">\${esc(s.server_name)}</td>
                            <td><span class="badge bg-secondary">\${esc(String(s.server_type || '').toUpperCase())}</span></td>
                            <td class="text-end">\${Number(s.sessions) || 0}</td>
                            <td class="text-end">
                                \${Number(s.transcode) > 0
                                    ? `<span class="badge bg-warning text-dark">\${Number(s.transcode)}</span>`
                                    : '0'}
                            </td>
                        </tr>
                    `).join('');
                }
            }

            const list = document.getElementById('live-modal-sessions');
            if (list) {
                const rows = Array.isArray(sessions) ? sessions : [];
                if (rows.length === 0) {
                    list.innerHTML = '<div class="list-group-item text-muted text-center py-3">No hay reproducciones activas</div>';
                } else {
                    list.innerHTML = rows.slice(0, 40).map(s => {
                        const method = s.play_method || 'direct_play';
                        const badge = playBadges[method] || 'secondary';
                        const label = playLabels[method] || method;
                        const title = s.title || 'Sin título';
                        const sub = s.subtitle || '';
                        const user = s.user || '—';
                        const server = s.server_name || '—';
                        const thumb = s.thumb_url
                            ? `<img src="\${esc(s.thumb_url)}" alt="" class="rounded me-2" width="40" height="60" style="object-fit:cover" loading="lazy">`
                            : `<div class="bg-secondary bg-opacity-25 rounded me-2 d-inline-flex align-items-center justify-content-center" style="width:40px;height:60px"><i class="bi bi-film text-muted"></i></div>`;
                        return `
                            <div class="list-group-item d-flex align-items-start gap-2 py-2">
                                \${thumb}
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-medium text-truncate">\${esc(title)}</div>
                                    \${sub ? `<div class="small text-muted text-truncate">\${esc(sub)}</div>` : ''}
                                    <div class="small text-muted text-truncate">
                                        \${esc(user)} · \${esc(server)}
                                        <span class="badge bg-\${badge} ms-1">\${esc(label)}</span>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            }

            const now = new Date();
            const stamp = now.toLocaleTimeString();
            setText('dash-live-refresh', stamp);
            setText('live-modal-updated', 'Actualizado a las ' + stamp);
            setText('dash-live-hint', streams === 1
                ? '1 reproducción activa · clic para detalle'
                : `\${streams} reproducciones activas · clic para detalle`);
        }

        async function refresh() {
            try {
                const res = await fetch('/activity/api', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                lastPayload = data;
                renderSummary(data.summary || null, data.sessions || []);
            } catch (err) {
                console.warn('No se pudo actualizar actividad en directo:', err);
                setText('dash-live-refresh', 'error');
                setText('live-modal-updated', 'Error al actualizar');
            }
        }

        refresh();
        timer = setInterval(refresh, REFRESH_MS);

        const modal = document.getElementById('liveActivityModal');
        if (modal) {
            modal.addEventListener('shown.bs.modal', () => {
                if (lastPayload) {
                    renderSummary(lastPayload.summary || null, lastPayload.sessions || []);
                }
                refresh();
            });
        }

        window.addEventListener('beforeunload', () => {
            if (timer) clearInterval(timer);
        });
    })();
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
