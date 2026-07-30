<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Estadísticas</h4>
    <a href="/stats/export" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Exportar CSV</a>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Sesiones hoy</p><h3><?= (int)($stats['streaming']['today_sessions'] ?? 0) ?></h3>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Horas hoy</p><h3><?= $stats['streaming']['today_hours'] ?? 0 ?>h</h3>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">MRR</p><h3><?= number_format((float)($stats['billing']['mrr'] ?? 0), 0) ?> €</h3>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-1">Streams activos</p><h3><?= (int)($stats['servers']['active_sessions'] ?? 0) ?></h3>
    </div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Streaming últimos 30 días</h6></div>
            <div class="card-body"><canvas id="streamingChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Por hora (7 días)</h6></div>
            <div class="card-body"><canvas id="hourlyChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top países</h6></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($countries as $c): ?>
                <li class="list-group-item d-flex justify-content-between"><span><?= e($c['country']) ?></span><span class="badge bg-primary"><?= (int)$c['count'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top dispositivos</h6></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($devices as $d): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="small"><?= e($d['device']) ?></span><span class="badge bg-info"><?= (int)$d['count'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top contenido</h6></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($topContent as $t): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="small text-truncate" style="max-width:70%"><?= e($t['title']) ?></span><span class="badge bg-success"><?= (int)$t['count'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$dailyJson = json_encode(array_column($daily, 'sessions'));
$dailyLabels = json_encode(array_column($daily, 'date'));
$hourlyJson = json_encode(array_column($hourly, 'sessions'));
$hourlyLabels = json_encode(array_column($hourly, 'hour'));
$scripts = <<<JS
<script>
const dailyData = {$dailyJson};
const dailyLabels = {$dailyLabels};
const hourlyData = {$hourlyJson};
const hourlyLabels = {$hourlyLabels};
new Chart(document.getElementById('streamingChart'), {
    type: 'line', data: { labels: dailyLabels, datasets: [{ label: 'Sesiones', data: dailyData, borderColor: '#0d6efd', tension: 0.3, fill: true, backgroundColor: 'rgba(13,110,253,0.1)' }] },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar', data: { labels: hourlyLabels.map(h => h + 'h'), datasets: [{ label: 'Sesiones', data: hourlyData, backgroundColor: '#6610f2' }] },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
