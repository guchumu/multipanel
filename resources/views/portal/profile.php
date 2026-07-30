<?php ob_start(); ?>
<h4 class="text-white mb-4">Mi perfil</h4>
<div class="card portal-card">
    <div class="card-body">
        <form method="POST" action="/portal/profile">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nombre visible</label><input name="display_name" class="form-control" value="<?= e($portalUser->display_name ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="<?= e($portalUser->email ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Idioma</label><input name="locale" class="form-control" value="<?= e($portalUser->locale ?? 'es') ?>"></div>
                <div class="col-md-6"><label class="form-label">Zona horaria</label><input name="timezone" class="form-control" value="<?= e($portalUser->timezone ?? 'Europe/Madrid') ?>"></div>
            </div>
            <button class="btn btn-primary mt-3">Guardar cambios</button>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
