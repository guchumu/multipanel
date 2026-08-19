<?php
$tg = is_array($telegramLink ?? null) ? $telegramLink : [];
$wa = is_array($whatsappLink ?? null) ? $whatsappLink : [];
$tgLinked = !empty($tg['linked']);
$tgDeep = (string) ($tg['deep_link'] ?? '');
$waPhone = (string) ($wa['phone'] ?? '');
$waOptIn = array_key_exists('opted_in', $wa) ? (bool) $wa['opted_in'] : true;
$waLink = (string) ($wa['wa_link'] ?? '');
$waCanAuto = !empty($wa['can_auto']);
$waDisplay = $waPhone;
if (str_starts_with($waPhone, '34') && strlen($waPhone) === 11) {
    $waDisplay = '+34 ' . substr($waPhone, 2);
} elseif ($waPhone !== '') {
    $waDisplay = '+' . $waPhone;
}
ob_start();
?>
<h1 class="portal-page-title">Mi ficha</h1>
<p class="portal-page-lead">Aquí guardas cómo te avisamos y tus datos.</p>

<div class="card portal-card mb-3">
    <div class="card-body">
        <h2 class="portal-section-title">📣 Avisos</h2>
        <p class="small text-muted mb-3">Te avisamos cuando se acaba el tiempo, cuando pagas, o si pides la contraseña.</p>

        <div class="ez-msg-grid">
            <div class="ez-msg-card<?= $tgLinked ? ' is-on' : '' ?>">
                <div class="ez-msg-ico" aria-hidden="true">✈️</div>
                <h3 class="ez-msg-title">Telegram</h3>
                <?php if ($tgLinked): ?>
                <p class="ez-msg-state">Vinculado ✅</p>
                <form method="POST" action="/portal/profile/telegram/unlink" class="mt-2">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Quitar</button>
                </form>
                <?php else: ?>
                <p class="ez-msg-state">Aún no</p>
                <?php if ($tgDeep !== ''): ?>
                <a class="ez-btn-tg" href="<?= e($tgDeep) ?>" target="_blank" rel="noopener">Abrir Telegram y pulsar Iniciar</a>
                <p class="small text-muted mt-2 mb-0">Luego recarga esta página. El bot te dirá «¡Listo!».</p>
                <?php else: ?>
                <form method="POST" action="/portal/profile/telegram">
                    <?= csrf_field() ?>
                    <button class="ez-btn-tg" type="submit">Vincular Telegram</button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="ez-msg-card<?= $waPhone !== '' ? ' is-on' : '' ?>">
                <div class="ez-msg-ico" aria-hidden="true">💬</div>
                <h3 class="ez-msg-title">WhatsApp</h3>
                <?php if ($waPhone !== ''): ?>
                <p class="ez-msg-state"><?= e($waDisplay) ?><?= $waOptIn ? ' ✅' : '' ?></p>
                <?php else: ?>
                <p class="ez-msg-state">Aún no</p>
                <?php endif; ?>
                <form method="POST" action="/portal/profile/whatsapp">
                    <?= csrf_field() ?>
                    <label class="form-label small mb-1" for="whatsapp_phone">Tu móvil</label>
                    <input id="whatsapp_phone" name="whatsapp_phone" class="form-control mb-2" type="tel" inputmode="tel"
                           value="<?= e($waPhone !== '' && str_starts_with($waPhone, '34') ? substr($waPhone, 2) : $waPhone) ?>"
                           placeholder="612345678" autocomplete="tel">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="whatsapp_opt_in" value="1" id="waOpt"
                               <?= $waOptIn || $waPhone === '' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="waOpt">Quiero avisos aquí</label>
                    </div>
                    <button class="btn btn-success w-100" type="submit">Guardar WhatsApp</button>
                </form>
                <?php if ($waLink !== ''): ?>
                <a class="btn btn-outline-success w-100 mt-2" href="<?= e($waLink) ?>" target="_blank" rel="noopener">Abrir WhatsApp y decir hola</a>
                <?php endif; ?>
                <p class="small text-muted mt-2 mb-0">
                    <?php if ($waCanAuto): ?>
                    Los avisos por WhatsApp están activados.
                    <?php else: ?>
                    Guardamos el número. Los avisos automáticos por WhatsApp los activa el administrador (WhatsApp Business). Telegram sí avisa en cuanto lo vinculas.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card portal-card mb-3">
    <div class="card-body">
        <h2 class="portal-section-title">Datos de contacto</h2>
        <dl class="row mb-3 small">
            <dt class="col-4 col-md-3 text-muted">Usuario</dt>
            <dd class="col-8 col-md-9"><?= e($portalUser->username ?? '') ?></dd>
        </dl>
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
