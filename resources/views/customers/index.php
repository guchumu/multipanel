<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><?= __('users') ?> CRM</h4>
    <a href="/customers/create" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i><?= __('create') ?></a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Total</small><h4><?= (int) $stats['total'] ?></h4></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted"><?= __('active') ?></small><h4 class="text-success"><?= (int) $stats['active'] ?></h4></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Prospectos</small><h4 class="text-info"><?= (int) $stats['prospect'] ?></h4></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Churned</small><h4 class="text-danger"><?= (int) $stats['churned'] ?></h4></div></div></div>
</div>

<form class="mb-3" method="GET">
    <div class="input-group" style="max-width:400px">
        <input name="q" class="form-control" placeholder="<?= __('search') ?>..." value="<?= e($search ?? '') ?>">
        <button class="btn btn-outline-secondary"><?= __('search') ?></button>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Email</th><th>Nombre</th><th>Empresa</th><th>Estado</th><th>Suscripciones</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No hay clientes</td></tr>
                <?php else: ?>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><?= e($c['email']) ?></td>
                    <td><?= e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))) ?></td>
                    <td><?= e($c['company'] ?? '-') ?></td>
                    <td><span class="badge bg-secondary"><?= e($c['status']) ?></span></td>
                    <td><?= (int) ($c['active_subs'] ?? 0) ?></td>
                    <td><a href="/customers/<?= e($c['uuid']) ?>" class="btn btn-sm btn-outline-primary"><?= __('edit') ?></a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
