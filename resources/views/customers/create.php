<?php ob_start(); ?>
<h4 class="mb-4">Nuevo cliente</h4>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="/customers">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Email *</label><input name="email" type="email" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Teléfono</label><input name="phone" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Nombre *</label><input name="first_name" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Apellidos</label><input name="last_name" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Empresa</label><input name="company" class="form-control"></div>
                <div class="col-md-6">
                    <label class="form-label">Estado</label>
                    <select name="status" class="form-select">
                        <option value="prospect">Prospecto</option>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Vincular usuario media</label>
                    <select name="media_user_id" class="form-select">
                        <option value="">— Ninguno —</option>
                        <?php foreach ($mediaUsers as $mu): ?>
                        <option value="<?= (int) $mu['id'] ?>"><?= e($mu['username']) ?> (<?= e($mu['email'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary mt-3"><?= __('save') ?></button>
            <a href="/customers" class="btn btn-link"><?= __('cancel') ?></a>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
