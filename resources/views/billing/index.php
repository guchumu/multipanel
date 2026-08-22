<?php ob_start(); ?>
<?php
$renewalPresets = is_array($renewalPresets ?? null) ? $renewalPresets : [];
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Facturación</h4>
    <div>
        <a href="/invoices" class="btn btn-outline-secondary btn-sm me-2"><i class="bi bi-receipt me-1"></i>Facturas</a>
        <a href="/settings#billing" class="btn btn-primary btn-sm"><i class="bi bi-gear me-1"></i>Editar precios</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <p class="text-muted small mb-1">Suscripciones activas</p>
            <h3><?= (int) $stats['active'] ?></h3>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <p class="text-muted small mb-1">Pagos vencidos</p>
            <h3 class="text-warning"><?= (int) $stats['past_due'] ?></h3>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <p class="text-muted small mb-1">Ingresos totales</p>
            <h3><?= number_format((float) $stats['revenue'], 2) ?> €</h3>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Precios</h6>
                <a href="/settings#billing" class="small">Editar</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php if ($renewalPresets === []): ?>
                <li class="list-group-item text-muted text-center">Sin precios. Configúralos en Ajustes.</li>
                <?php else: ?>
                <?php foreach ($renewalPresets as $preset): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= e((string) ($preset['label'] ?? '')) ?></strong>
                        <br><small class="text-muted"><?= (int) ($preset['days'] ?? 0) ?> días</small>
                    </div>
                    <span class="badge bg-primary"><?= number_format((float) ($preset['price'] ?? 0), 2) ?> €</span>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <div class="card-footer bg-white small text-muted">
                Los mismos de <a href="/settings#billing">Configuración → Facturación</a>
                (portal, renovaciones y reenganche).
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Suscripciones recientes</h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Cliente</th><th>Plan</th><th>Estado</th><th>Importe</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($subscriptions)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Sin suscripciones</td></tr>
                        <?php else: ?>
                        <?php foreach ($subscriptions as $sub): ?>
                        <tr>
                            <td><?= e($sub['customer_email']) ?></td>
                            <td><?= e($sub['plan_name']) ?></td>
                            <td><span class="badge bg-<?= $sub['status'] === 'active' ? 'success' : 'warning' ?>"><?= e($sub['status']) ?></span></td>
                            <td><?= number_format((float) $sub['amount'], 2) ?> <?= e($sub['currency']) ?></td>
                            <td>
                                <?php if ($sub['status'] === 'past_due'): ?>
                                <form method="POST" action="/billing/subscriptions/<?= (int) $sub['id'] ?>/pay" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-success">Marcar pagado</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
