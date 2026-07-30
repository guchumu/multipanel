<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Diagnósticos del sistema</h4>
    <button class="btn btn-outline-primary btn-sm" id="btnRefresh"><i class="bi bi-arrow-clockwise"></i> Re-ejecutar</button>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-4">
            <div class="position-relative d-inline-block">
                <svg width="120" height="120">
                    <circle cx="60" cy="60" r="54" fill="none" stroke="#e9ecef" stroke-width="8"/>
                    <circle cx="60" cy="60" r="54" fill="none" stroke="<?= $score >= 80 ? '#198754' : ($score >= 50 ? '#ffc107' : '#dc3545') ?>" stroke-width="8"
                        stroke-dasharray="<?= 339.292 * $score / 100 ?> 339.292" transform="rotate(-90 60 60)"/>
                </svg>
                <div class="position-absolute top-50 start-50 translate-middle">
                    <h2 class="mb-0" id="health-score"><?= (int) $score ?>%</h2>
                </div>
            </div>
            <p class="text-muted mt-2 mb-0">Salud del sistema</p>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Información PHP</h6></div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($phpInfo as $k => $v): ?>
                    <div class="col-md-6 mb-2"><span class="text-muted small"><?= e(ucfirst($k)) ?>:</span> <strong><?= e($v) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h6 class="mb-0">Comprobaciones</h6></div>
    <div class="list-group list-group-flush" id="checks-list">
        <?php foreach ($checks as $check): ?>
        <?php
        $icon = match ($check['status']) {
            'ok' => 'check-circle-fill text-success',
            'error' => 'x-circle-fill text-danger',
            'warning' => 'exclamation-triangle-fill text-warning',
            default => 'info-circle-fill text-info',
        };
        ?>
        <div class="list-group-item d-flex align-items-center">
            <i class="bi bi-<?= $icon ?> me-3 fs-5"></i>
            <div class="flex-grow-1">
                <strong><?= e($check['name']) ?></strong>
                <br><small class="text-muted"><?= e($check['message']) ?></small>
            </div>
            <span class="badge bg-<?= $check['status'] === 'ok' ? 'success' : ($check['status'] === 'error' ? 'danger' : 'secondary') ?>"><?= e($check['status']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h6 class="mb-0">Licencia</h6></div>
    <div class="card-body">
        <?php if ($license): ?>
        <dl class="row mb-3">
            <dt class="col-sm-3">Clave</dt><dd class="col-sm-9"><code><?= e($license['key']) ?></code></dd>
            <dt class="col-sm-3">Plan</dt><dd class="col-sm-9"><?= e($license['plan']) ?></dd>
            <dt class="col-sm-3">Estado</dt><dd class="col-sm-9"><span class="badge bg-<?= ($license['valid'] ?? false) ? 'success' : 'danger' ?>"><?= ($license['valid'] ?? false) ? 'Válida' : 'Inválida' ?></span></dd>
            <?php if ($license['expires_at']): ?>
            <dt class="col-sm-3">Expira</dt><dd class="col-sm-9"><?= e($license['expires_at']) ?></dd>
            <?php endif; ?>
        </dl>
        <?php else: ?>
        <p class="text-muted">Sin licencia activa (modo desarrollo).</p>
        <?php endif; ?>
        <form method="POST" action="/diagnostics/license" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-md-8"><input name="license_key" class="form-control" placeholder="Pegar clave de licencia..."></div>
            <div class="col-md-4"><button class="btn btn-primary w-100">Activar licencia</button></div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
document.getElementById('btnRefresh')?.addEventListener('click', async () => {
    const res = await fetch('/diagnostics/run');
    const data = await res.json();
    document.getElementById('health-score').textContent = data.score + '%';
    location.reload();
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
