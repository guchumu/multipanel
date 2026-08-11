<?php
/** @var array $estimate */
/** @var array $servers */
/** @var int|null $currentServerId */
/** @var int $monthsAhead */
$months = $estimate['months'] ?? [];
$totals = $estimate['totals'] ?? ['caducidades' => 0, 'renovaciones' => 0, 'importe' => 0.0];
$defaultPrice = (float) ($estimate['default_price'] ?? 0);
$formatMoney = static fn (float $n): string => number_format($n, 2, ',', '.') . ' €';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="min-w-0">
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1 text-truncate">Estimación mensual</h4>
        <p class="text-muted small mb-0">
            Caducidades previstas por <code>expires_at</code> y renovaciones estimadas (mismo grupo, asumiendo que renuevan).
            Desglose por servidor · mes actual + <?= (int) $monthsAhead ?> siguientes.
        </p>
    </div>
    <a href="/media-users/expiring" class="btn btn-outline-warning btn-sm flex-shrink-0">
        <i class="bi bi-hourglass-split me-1"></i>Vencimientos
    </a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/media-users/estimacion" class="d-flex flex-wrap gap-2 gap-md-3 align-items-center">
            <label class="small text-muted mb-0">Servidor:</label>
            <select name="server_id" class="form-select form-select-sm" style="min-width: 140px; max-width: 220px;" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($servers as $server): ?>
                <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>><?= e($server->name) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="small text-muted mb-0">Horizonte:</label>
            <select name="months" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                <?php foreach ([6, 12, 18, 24] as $opt): ?>
                <option value="<?= $opt ?>" <?= $monthsAhead === $opt ? 'selected' : '' ?>><?= $opt ?> meses</option>
                <?php endforeach; ?>
            </select>
            <span class="small text-muted ms-md-auto">
                Total periodo: <strong><?= (int) $totals['caducidades'] ?></strong> caducidades
                · Importe est. <strong><?= e($formatMoney((float) $totals['importe'])) ?></strong>
            </span>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <p class="text-muted small mb-1">Caducidades (periodo)</p>
            <h3 class="mb-0"><?= (int) $totals['caducidades'] ?></h3>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <p class="text-muted small mb-1">Renovaciones est.</p>
            <h3 class="mb-0"><?= (int) $totals['renovaciones'] ?></h3>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <p class="text-muted small mb-1">Importe estimado</p>
            <h3 class="mb-0"><?= e($formatMoney((float) $totals['importe'])) ?></h3>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <p class="text-muted small mb-1">Precio por defecto</p>
            <h3 class="mb-0"><?= e($formatMoney($defaultPrice)) ?></h3>
            <p class="text-muted small mb-0 mt-1">Preset ~1 mes si no hay cobro</p>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Caducidades / renovaciones por mes</h6>
        <span class="small text-muted">Barras = caducidades previstas</span>
    </div>
    <div class="card-body">
        <?php if ((int) $totals['caducidades'] === 0): ?>
        <p class="text-muted text-center py-4 mb-0">No hay usuarios con fecha de caducidad en este periodo.</p>
        <?php else: ?>
        <canvas id="estimacionChart" height="110"></canvas>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h6 class="mb-0">Detalle mensual</h6>
        <p class="small text-muted mb-0 mt-1">
            Expande cada mes para ver el desglose por servidor.
            Importe = suscripción activa, último pago o preset mensual (<?= e($formatMoney($defaultPrice)) ?>).
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 2.5rem;"></th>
                    <th>Mes</th>
                    <th class="text-end">Caducidades</th>
                    <th class="text-end">Renovaciones est.</th>
                    <th class="text-end">Importe est.</th>
                    <th class="d-none d-md-table-cell">Servidores</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($months === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Sin datos</td></tr>
                <?php else: foreach ($months as $i => $month): ?>
                <?php
                    $rowId = 'est-month-' . $i;
                    $byServer = $month['by_server'] ?? [];
                    $serverCount = count($byServer);
                ?>
                <tr class="<?= !empty($month['is_current']) ? 'table-primary' : '' ?>">
                    <td>
                        <?php if ($serverCount > 0): ?>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" type="button"
                                data-bs-toggle="collapse" data-bs-target="#<?= e($rowId) ?>"
                                aria-expanded="false" aria-controls="<?= e($rowId) ?>" title="Ver por servidor">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($month['label']) ?></strong>
                        <?php if (!empty($month['is_current'])): ?>
                        <span class="badge bg-primary ms-1">Actual</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int) $month['caducidades'] ?></td>
                    <td class="text-end"><?= (int) $month['renovaciones'] ?></td>
                    <td class="text-end text-nowrap"><?= e($formatMoney((float) $month['importe'])) ?></td>
                    <td class="d-none d-md-table-cell small text-muted">
                        <?php if ($serverCount === 0): ?>
                        —
                        <?php else: ?>
                        <?= $serverCount ?> servidor<?= $serverCount === 1 ? '' : 'es' ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($serverCount > 0): ?>
                <tr class="collapse" id="<?= e($rowId) ?>">
                    <td colspan="6" class="bg-light p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr class="small text-muted">
                                        <th class="ps-4">Servidor</th>
                                        <th class="text-end">Caducidades</th>
                                        <th class="text-end">Renovaciones est.</th>
                                        <th class="text-end pe-3">Importe est.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($byServer as $srv): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="badge bg-light text-dark border"><?= e($srv['server_name']) ?></span>
                                        </td>
                                        <td class="text-end"><?= (int) $srv['caducidades'] ?></td>
                                        <td class="text-end"><?= (int) $srv['renovaciones'] ?></td>
                                        <td class="text-end pe-3 text-nowrap"><?= e($formatMoney((float) $srv['importe'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <th></th>
                    <th>Total</th>
                    <th class="text-end"><?= (int) $totals['caducidades'] ?></th>
                    <th class="text-end"><?= (int) $totals['renovaciones'] ?></th>
                    <th class="text-end text-nowrap"><?= e($formatMoney((float) $totals['importe'])) ?></th>
                    <th class="d-none d-md-table-cell"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<p class="small text-muted mt-3 mb-0">
    Se incluyen usuarios no borrados con estado active / invited / suspended / expired y <code>expires_at</code> en el mes.
    Las renovaciones estimadas coinciden con las caducidades (hipótesis de renovación al 100&nbsp;%).
</p>
<?php
$content = ob_get_clean();
$scripts = '';
if ((int) $totals['caducidades'] > 0) {
    $labels = json_encode(array_map(static fn (array $m): string => $m['label'], $months), JSON_UNESCAPED_UNICODE);
    $caducidades = json_encode(array_map(static fn (array $m): int => (int) $m['caducidades'], $months));
    $importes = json_encode(array_map(static fn (array $m): float => (float) $m['importe'], $months));
    $scripts = <<<HTML
<script>
(function () {
    const ctx = document.getElementById('estimacionChart');
    if (!ctx || typeof Chart === 'undefined') return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$labels},
            datasets: [
                {
                    label: 'Caducidades / renovaciones est.',
                    data: {$caducidades},
                    backgroundColor: 'rgba(13, 110, 253, 0.65)',
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    label: 'Importe est. (€)',
                    data: {$importes},
                    type: 'line',
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.15)',
                    tension: 0.3,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Usuarios' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '€' } }
            }
        }
    });
})();
</script>
HTML;
}
include base_path('resources/views/layouts/app.php');
?>
