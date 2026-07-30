<?php ob_start(); ?>
<h4 class="mb-4"><?= e($customer['first_name'] ?? $customer['email']) ?></h4>
<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/customers/<?= e($customer['uuid']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_method" value="PUT">
                    <div class="mb-3"><label class="form-label">Email</label><input name="email" class="form-control" value="<?= e($customer['email']) ?>"></div>
                    <div class="row g-2">
                        <div class="col"><input name="first_name" class="form-control" placeholder="Nombre" value="<?= e($customer['first_name'] ?? '') ?>"></div>
                        <div class="col"><input name="last_name" class="form-control" placeholder="Apellidos" value="<?= e($customer['last_name'] ?? '') ?>"></div>
                    </div>
                    <div class="mb-3 mt-2"><input name="company" class="form-control" placeholder="Empresa" value="<?= e($customer['company'] ?? '') ?>"></div>
                    <div class="mb-3"><input name="phone" class="form-control" placeholder="Teléfono" value="<?= e($customer['phone'] ?? '') ?>"></div>
                    <div class="mb-3">
                        <select name="status" class="form-select">
                            <?php foreach (['prospect','active','inactive','churned'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($customer['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><textarea name="notes" class="form-control" rows="3" placeholder="Notas"><?= e($customer['notes'] ?? '') ?></textarea></div>
                    <button class="btn btn-primary"><?= __('save') ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Suscripciones</strong></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($subscriptions)): ?>
                <li class="list-group-item text-muted">Sin suscripciones</li>
                <?php else: ?>
                <?php foreach ($subscriptions as $s): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><?= e($s['plan_name']) ?></span>
                    <span class="badge bg-secondary"><?= e($s['status']) ?></span>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
