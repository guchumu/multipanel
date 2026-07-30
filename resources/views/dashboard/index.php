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
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('usersChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Activos', 'Suspendidos', 'Pendientes', 'Invitados'],
                datasets: [{
                    data: [
                        <?= (int) $stats['users_active'] ?>,
                        <?= (int) $stats['users_suspended'] ?>,
                        <?= (int) $stats['users_pending'] ?>,
                        <?= (int) $stats['users_invited'] ?>
                    ],
                    backgroundColor: ['#198754', '#ffc107', '#6c757d', '#0dcaf0']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }

    if (window.MultiPanelRealtime) {
        window.MultiPanelRealtime.connect();
    }
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
