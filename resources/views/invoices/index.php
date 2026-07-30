<?php ob_start(); ?>
<h4 class="mb-4">Facturas</h4>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Número</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Fecha</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($invoices)): ?>
            <tr><td colspan="6" class="text-muted text-center py-4">No hay facturas</td></tr>
            <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
            <tr>
                <td><strong><?= e($inv['number']) ?></strong></td>
                <td><?= e(trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')) ?: $inv['customer_email']) ?></td>
                <td><?= number_format((float) $inv['total'], 2) ?> <?= e($inv['currency']) ?></td>
                <td><span class="badge bg-<?= $inv['status'] === 'paid' ? 'success' : 'secondary' ?>"><?= e($inv['status']) ?></span></td>
                <td class="small"><?= e($inv['created_at']) ?></td>
                <td>
                    <a href="/invoices/<?= (int) $inv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">Ver</a>
                    <a href="/invoices/<?= (int) $inv['id'] ?>/download" class="btn btn-sm btn-outline-secondary">Descargar</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
