<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Facturación</h4>
    <div>
        <a href="/invoices" class="btn btn-outline-secondary btn-sm me-2"><i class="bi bi-receipt me-1"></i>Facturas</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#planModal"><i class="bi bi-plus-lg me-1"></i>Nuevo plan</button>
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
            <div class="card-header bg-white"><h6 class="mb-0">Planes</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($plans)): ?>
                <li class="list-group-item text-muted text-center">Sin planes</li>
                <?php else: ?>
                <?php foreach ($plans as $plan): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <div>
                        <strong><?= e($plan['name']) ?></strong>
                        <br><small class="text-muted"><?= e($plan['interval']) ?></small>
                    </div>
                    <span class="badge bg-primary"><?= number_format((float) $plan['price'], 2) ?> <?= e($plan['currency']) ?></span>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
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

<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/billing/plans" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Nuevo plan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nombre</label><input name="name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Precio</label><input name="price" type="number" step="0.01" class="form-control" required></div>
                <div class="mb-3">
                    <label class="form-label">Intervalo</label>
                    <select name="interval" class="form-select">
                        <option value="monthly">Mensual</option>
                        <option value="quarterly">Trimestral</option>
                        <option value="yearly">Anual</option>
                        <option value="lifetime">Vitalicio</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Crear plan</button></div>
        </form>
    </div>
</div>

<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
