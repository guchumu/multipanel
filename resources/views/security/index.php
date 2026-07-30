<?php ob_start(); ?>
<h4 class="mb-4"><i class="bi bi-shield-exclamation me-2"></i><?= __('security') ?></h4>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>IP Blacklist</strong></div>
            <div class="card-body">
                <form method="POST" action="/security/block">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label">IP</label><input name="ip_address" class="form-control" placeholder="192.168.1.100" required></div>
                    <div class="mb-3"><label class="form-label">Motivo</label><input name="reason" class="form-control"></div>
                    <button class="btn btn-danger btn-sm">Bloquear IP</button>
                </form>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($blacklist)): ?>
                <li class="list-group-item text-muted">Sin IPs bloqueadas</li>
                <?php else: ?>
                <?php foreach ($blacklist as $row): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><code><?= e($row['ip_address']) ?></code><br><small class="text-muted"><?= e($row['reason'] ?? '') ?></small></span>
                    <form method="POST" action="/security/<?= (int) $row['id'] ?>/unblock"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Desbloquear</button></form>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Políticas ABAC</strong></div>
            <div class="card-body">
                <p class="text-muted small">Motor de políticas por atributos. Configuración en <code>config/abac.php</code></p>
                <?php foreach ($policies as $p): ?>
                <div class="border rounded p-2 mb-2 small">
                    <strong><?= e($p['action'] ?? '*') ?></strong> → <span class="badge bg-<?= ($p['effect'] ?? '') === 'allow' ? 'success' : 'danger' ?>"><?= e($p['effect'] ?? '') ?></span>
                    <?php if (!empty($p['conditions'])): ?>
                    <pre class="mb-0 mt-1"><?= e(json_encode($p['conditions'], JSON_PRETTY_PRINT)) ?></pre>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="alert alert-info mt-3 small mb-0">
            Headers de seguridad activos globalmente (CSP frame, nosniff, XSS). Instalador bloqueado post-setup.
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
