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

<?php
$content = ob_get_clean();

// IMPORTANTE: nunca metas tags <?= ?> dentro de un heredoc/nowdoc (<<<JS / <<<'JS').
// El lexer de PHP trata todo el bloque como una única cadena literal, así que esos
// tags no se ejecutan nunca: llegan al navegador como texto suelto dentro del
// <script>, rompen la sintaxis JS y el gráfico (y todo lo que va detrás) se queda
// sin pintar. Por eso el donut de "Distribución de usuarios" nunca se veía.
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
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
