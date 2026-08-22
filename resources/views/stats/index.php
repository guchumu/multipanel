<?php ob_start();
$period = $period ?? ['preset' => '30d', 'from_date' => '', 'to_date' => '', 'label' => 'Últimos 30 días'];
$mediaType = $mediaType ?? '';
$filterQuery = $filterQuery ?? [];
$topUsers = $topUsers ?? [];
$typeBreakdown = $typeBreakdown ?? ['movie' => 0, 'series' => 0, 'other' => 0];

$qs = static function (array $extra = []) use ($period, $mediaType): string {
    $params = [
        'preset' => $extra['preset'] ?? $period['preset'],
        'type' => array_key_exists('type', $extra) ? $extra['type'] : $mediaType,
        'from' => $extra['from'] ?? ($period['preset'] === 'custom' ? $period['from_date'] : null),
        'to' => $extra['to'] ?? ($period['preset'] === 'custom' ? $period['to_date'] : null),
    ];
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return $params === [] ? '/stats' : '/stats?' . http_build_query($params);
};
$exportQs = $filterQuery === [] ? '/stats/export' : '/stats/export?' . http_build_query($filterQuery);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">Estadísticas</h4>
        <small class="text-muted"><?= e($period['label']) ?></small>
    </div>
    <a href="<?= e($exportQs) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Exportar CSV</a>
</div>

<form method="GET" action="/stats" class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small mb-1">Periodo</label>
                <div class="btn-group btn-group-sm">
                    <a href="<?= e($qs(['preset' => '7d', 'from' => null, 'to' => null])) ?>" class="btn btn-outline-primary <?= $period['preset'] === '7d' ? 'active' : '' ?>">7 días</a>
                    <a href="<?= e($qs(['preset' => '30d', 'from' => null, 'to' => null])) ?>" class="btn btn-outline-primary <?= $period['preset'] === '30d' ? 'active' : '' ?>">30 días</a>
                    <a href="<?= e($qs(['preset' => 'week', 'from' => null, 'to' => null])) ?>" class="btn btn-outline-primary <?= $period['preset'] === 'week' ? 'active' : '' ?>">Esta semana</a>
                    <a href="<?= e($qs(['preset' => 'month', 'from' => null, 'to' => null])) ?>" class="btn btn-outline-primary <?= $period['preset'] === 'month' ? 'active' : '' ?>">Este mes</a>
                </div>
            </div>
            <div>
                <label class="form-label small mb-1">Tipo</label>
                <div class="btn-group btn-group-sm">
                    <a href="<?= e($qs(['type' => ''])) ?>" class="btn btn-outline-secondary <?= $mediaType === '' ? 'active' : '' ?>">Todo</a>
                    <a href="<?= e($qs(['type' => 'movie'])) ?>" class="btn btn-outline-secondary <?= $mediaType === 'movie' ? 'active' : '' ?>">Películas</a>
                    <a href="<?= e($qs(['type' => 'series'])) ?>" class="btn btn-outline-secondary <?= $mediaType === 'series' ? 'active' : '' ?>">Series</a>
                </div>
            </div>
            <div class="ms-md-auto d-flex flex-wrap gap-2 align-items-end">
                <input type="hidden" name="preset" value="custom">
                <?php if ($mediaType !== ''): ?>
                <input type="hidden" name="type" value="<?= e($mediaType) ?>">
                <?php endif; ?>
                <div>
                    <label class="form-label small mb-1" for="statsFrom">Desde</label>
                    <input type="date" id="statsFrom" name="from" class="form-control form-control-sm" value="<?= e($period['from_date']) ?>">
                </div>
                <div>
                    <label class="form-label small mb-1" for="statsTo">Hasta</label>
                    <input type="date" id="statsTo" name="to" class="form-control form-control-sm" value="<?= e($period['to_date']) ?>">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
            </div>
        </div>
    </div>
</form>

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
            <div class="card-header bg-white"><h6 class="mb-0">Streaming · <?= e($period['label']) ?></h6></div>
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
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0">Por hora</h6></div>
            <div class="card-body">
                <?php if (empty($hourly)): ?>
                <p class="text-muted text-center py-4 mb-0">Sin datos horarios.</p>
                <?php else: ?>
                <canvas id="hourlyChart" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Series vs películas</h6></div>
            <div class="card-body">
                <?php if (($typeBreakdown['movie'] + $typeBreakdown['series'] + $typeBreakdown['other']) === 0): ?>
                <p class="text-muted text-center py-4 mb-0">Sin datos de tipo.</p>
                <?php else: ?>
                <canvas id="typeChart" height="180"></canvas>
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
                <?php if (empty($countries)): ?><li class="list-group-item text-muted small">Sin datos de país. Se rellenan al reproducir (IP pública, no LAN).</li>
                <?php else: foreach ($countries as $c): ?>
                <li class="list-group-item d-flex justify-content-between"><span><?= e($c['country']) ?></span><span class="badge bg-primary"><?= (int)$c['count'] ?></span></li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Quienes más reproducen</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($topUsers)): ?><li class="list-group-item text-muted small">Sin datos de usuarios.</li>
                <?php else: foreach ($topUsers as $u): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <?php if (!empty($u['uuid'])): ?>
                    <a href="/media-users/<?= e($u['uuid']) ?>" class="small text-truncate text-decoration-none"><?= e($u['name']) ?></a>
                    <?php else: ?>
                    <span class="small text-truncate"><?= e($u['name']) ?></span>
                    <?php endif; ?>
                    <span class="badge bg-dark"><?= (int)$u['count'] ?></span>
                </li>
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
</div>

<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Top contenido</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($topContent)): ?><li class="list-group-item text-muted small">Sin datos</li>
                <?php else: foreach ($topContent as $t): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="small text-truncate" style="max-width:80%"><?= e($t['title']) ?></span><span class="badge bg-success"><?= (int)$t['count'] ?></span></li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = '';
$hasCharts = !empty($daily) || !empty($hourly) || (($typeBreakdown['movie'] + $typeBreakdown['series'] + $typeBreakdown['other']) > 0);
if ($hasCharts) {
    $dailyJson = json_encode(array_column($daily, 'sessions'));
    $dailyLabels = json_encode(array_column($daily, 'date'));
    $hourlyJson = json_encode(array_column($hourly, 'sessions'));
    $hourlyLabels = json_encode(array_column($hourly, 'hour'));
    $typeJson = json_encode([
        (int) $typeBreakdown['movie'],
        (int) $typeBreakdown['series'],
        (int) $typeBreakdown['other'],
    ]);
    $scripts = "<script>\n";
    if (!empty($daily)) {
        $scripts .= "new Chart(document.getElementById('streamingChart'), { type: 'line', data: { labels: {$dailyLabels}, datasets: [{ label: 'Sesiones', data: {$dailyJson}, borderColor: '#0d6efd', tension: 0.3, fill: true, backgroundColor: 'rgba(13,110,253,0.1)' }] }, options: { responsive: true, plugins: { legend: { display: false } } } });\n";
    }
    if (!empty($hourly)) {
        $scripts .= "const hourlyLabels = {$hourlyLabels}; new Chart(document.getElementById('hourlyChart'), { type: 'bar', data: { labels: hourlyLabels.map(h => h + 'h'), datasets: [{ label: 'Sesiones', data: {$hourlyJson}, backgroundColor: '#6610f2' }] }, options: { responsive: true, plugins: { legend: { display: false } } } });\n";
    }
    if (($typeBreakdown['movie'] + $typeBreakdown['series'] + $typeBreakdown['other']) > 0) {
        $scripts .= "new Chart(document.getElementById('typeChart'), { type: 'doughnut', data: { labels: ['Películas', 'Series', 'Otros'], datasets: [{ data: {$typeJson}, backgroundColor: ['#e5a00d', '#00a4dc', '#6c757d'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });\n";
    }
    $scripts .= "</script>";
}
include base_path('resources/views/layouts/app.php');
