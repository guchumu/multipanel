<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Estadísticas</h4>
    <a href="/stats/export" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Exportar CSV</a>
</div>

<div class="row g-4 mb-4">
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Servidores online</p>
        <h3 class="mb-0"><?= (int)($stats['servers']['online'] ?? 0) ?>/<?= (int)($stats['servers']['total'] ?? 0) ?></h3>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Streams activos</p>
        <h3 class="mb-0"><?= (int)($stats['streaming']['live_sessions'] ?? $stats['servers']['active_sessions'] ?? 0) ?></h3>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Sesiones hoy</p><h3><?= (int)($stats['streaming']['today_sessions'] ?? 0) ?></h3>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Usuarios activos</p><h3><?= (int)($stats['users']['active'] ?? 0) ?></h3>
    </div></div></div>
</div>

<?php if (($stats['servers']['online'] ?? 0) === 0 && ($stats['servers']['total'] ?? 0) > 0): ?>
<div class="alert alert-warning">
    Ningún servidor online. Los datos se actualizan al sincronizar.
    Ve a <a href="/servers">Servidores</a> — se reconecta automáticamente al entrar, o pulsa el icono <i class="bi bi-bug"></i> para ver el debug de conexión.
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Streaming últimos 30 días</h6></div>
            <div class="card-body">
                <?php if (empty($daily)): ?>
                <p class="text-muted text-center py-4 mb-0">Sin histórico aún. Sincroniza servidores para registrar actividad.</p>
                <?php else: ?>
                <canvas id="streamingChart" height="120"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Por hora (7 días)</h6></div>
            <div class="card-body">
                <?php if (empty($hourly)): ?>
                <p class="text-muted text-center py-4 mb-0">Sin datos horarios.</p>
                <?php else: ?>
                <canvas id="hourlyChart" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top países</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($countries)): ?><li class="list-group-item text-muted small">Sin datos</li>
                <?php else: foreach ($countries as $c): ?>
                <li class="list-group-item d-flex justify-content-between"><span><?= e($c['country']) ?></span><span class="badge bg-primary"><?= (int)$c['count'] ?></span></li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top dispositivos</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($devices)): ?><li class="list-group-item text-muted small">Sin datos</li>
                <?php else: foreach ($devices as $d): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="small"><?= e($d['device']) ?></span><span class="badge bg-info"><?= (int)$d['count'] ?></span></li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top contenido</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($topContent)): ?><li class="list-group-item text-muted small">Sin datos</li>
                <?php else: foreach ($topContent as $t): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="small text-truncate" style="max-width:70%"><?= e($t['title']) ?></span><span class="badge bg-success"><?= (int)$t['count'] ?></span></li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = '';
if (!empty($daily) || !empty($hourly)) {
    $dailyJson = json_encode(array_column($daily, 'sessions'));
    $dailyLabels = json_encode(array_column($daily, 'date'));
    $hourlyJson = json_encode(array_column($hourly, 'sessions'));
    $hourlyLabels = json_encode(array_column($hourly, 'hour'));
    $scripts = "<script>\n";
    if (!empty($daily)) {
        $scripts .= "new Chart(document.getElementById('streamingChart'), { type: 'line', data: { labels: {$dailyLabels}, datasets: [{ label: 'Sesiones', data: {$dailyJson}, borderColor: '#0d6efd', tension: 0.3, fill: true, backgroundColor: 'rgba(13,110,253,0.1)' }] }, options: { responsive: true, plugins: { legend: { display: false } } } });\n";
    }
    if (!empty($hourly)) {
        $scripts .= "const hourlyLabels = {$hourlyLabels}; new Chart(document.getElementById('hourlyChart'), { type: 'bar', data: { labels: hourlyLabels.map(h => h + 'h'), datasets: [{ label: 'Sesiones', data: {$hourlyJson}, backgroundColor: '#6610f2' }] }, options: { responsive: true, plugins: { legend: { display: false } } } });\n";
    }
    $scripts .= "</script>";
}
include base_path('resources/views/layouts/app.php');
