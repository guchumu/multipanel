<?php ob_start(); ?>
<h1 class="portal-page-title">Mi perfil</h1>
<p class="portal-page-lead">Datos de tu cuenta. El usuario de acceso no se puede cambiar aquí.</p>

<div class="card portal-card mb-3">
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-4 col-md-3 text-muted">Usuario</dt>
            <dd class="col-8 col-md-9"><?= e($portalUser->username ?? '') ?></dd>
            <dt class="col-4 col-md-3 text-muted">Telegram</dt>
            <dd class="col-8 col-md-9">
                <?php if (!empty($portalUser->telegram_chat_id)): ?>
                Vinculado <span class="text-muted">(<?= e((string) $portalUser->telegram_chat_id) ?>)</span>
                <?php else: ?>
                <span class="text-muted">No vinculado</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>

<div class="card portal-card mb-4">
    <div class="card-body">
        <h2 class="portal-section-title">Datos de contacto</h2>
        <form method="POST" action="/portal/profile">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="display_name">Nombre visible</label>
                    <input id="display_name" name="display_name" class="form-control" value="<?= e($portalUser->display_name ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">Email</label>
                    <input id="email" name="email" type="email" class="form-control" value="<?= e($portalUser->email ?? '') ?>" autocomplete="email">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="locale">Idioma</label>
                    <input id="locale" name="locale" class="form-control" value="<?= e($portalUser->locale ?? 'es') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="timezone">Zona horaria</label>
                    <input id="timezone" name="timezone" class="form-control" value="<?= e($portalUser->timezone ?? 'Europe/Madrid') ?>">
                </div>
            </div>
            <button class="btn btn-primary mt-3" type="submit">Guardar cambios</button>
        </form>
    </div>
</div>

<div class="card portal-card mb-3">
    <div class="card-body">
        <h2 class="portal-section-title">Cambiar contraseña</h2>
        <p class="small text-muted">Mínimo 8 caracteres. Afecta al acceso a este portal.</p>
        <form method="POST" action="/portal/profile/password" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="current_password">Contraseña actual</label>
                <input id="current_password" type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_password">Nueva contraseña</label>
                <input id="new_password" type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_password_confirmation">Repetir nueva contraseña</label>
                <input id="new_password_confirmation" type="password" name="new_password_confirmation" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <button class="btn btn-outline-primary" type="submit">Actualizar contraseña</button>
        </form>
    </div>
</div>

<p class="text-white-50 small mb-0"><a class="link-light" href="/portal">← Volver al inicio</a></p>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
