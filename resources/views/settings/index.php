<?php ob_start(); ?>
<h4 class="mb-4">Configuración</h4>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#general">General</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#smtp">Email / SMTP</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#telegram">Telegram</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#billing">Facturación</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#security">Seguridad</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#oauth">SSO / OAuth</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="general">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="general">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nombre aplicación</label>
                            <input name="app_name" class="form-control" value="<?= e($settings['app_name'] ?? config('app.name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Zona horaria</label>
                            <input name="app_timezone" class="form-control" value="<?= e($settings['app_timezone'] ?? config('app.timezone')) ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3">Guardar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="smtp">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="smtp">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Host SMTP</label><input name="mail_host" class="form-control" value="<?= e($settings['mail_host'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Puerto</label><input name="mail_port" class="form-control" value="<?= e($settings['mail_port'] ?? '587') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Usuario</label><input name="mail_username" class="form-control" value="<?= e($settings['mail_username'] ?? '') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Contraseña</label><input type="password" name="mail_password" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Email remitente</label><input name="mail_from" class="form-control" value="<?= e($settings['mail_from'] ?? '') ?>"></div>
                    </div>
                    <button class="btn btn-primary mt-3">Guardar SMTP</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="telegram">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="telegram">
                    <div class="mb-3"><label class="form-label">Bot Token</label><input name="telegram_bot_token" class="form-control" value="<?= e($settings['telegram_bot_token'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="form-label">Chat ID</label><input name="telegram_chat_id" class="form-control" value="<?= e($settings['telegram_chat_id'] ?? '') ?>"></div>
                    <button class="btn btn-primary">Guardar Telegram</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="billing">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings/billing" id="billingForm">
                    <?= csrf_field() ?>

                    <div class="mb-4 p-3 border rounded bg-light">
                        <h6 class="d-flex align-items-center gap-2">
                            <i class="bi bi-credit-card-2-front text-primary"></i>Conexión con Stripe
                            <?php if ($stripeHasSecretKey): ?>
                            <span class="badge bg-success">Configurado</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Sin configurar</span>
                            <?php endif; ?>
                        </h6>
                        <p class="form-text mt-0">
                            Pega aquí tus claves de <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/apikeys</a>.
                            Sin esto, los botones de "Cobro con Stripe" de las fichas de usuario no podrán generar enlaces de pago.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small">Clave secreta (Secret key, empieza por <code>sk_</code>)</label>
                                <input type="password" name="stripe_secret_key" class="form-control" autocomplete="off"
                                       placeholder="<?= $stripeHasSecretKey ? e($stripeSecretKeyMasked) : 'sk_live_...' ?>">
                                <div class="form-text">Déjalo en blanco para no cambiar la clave ya guardada.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Clave pública (Publishable key, empieza por <code>pk_</code>)</label>
                                <input type="text" name="stripe_publishable_key" class="form-control" autocomplete="off"
                                       value="<?= e($stripePublishableKey) ?>" placeholder="pk_live_...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">
                                    Webhook signing secret (empieza por <code>whsec_</code>)
                                    <?php if ($stripeHasWebhookSecret): ?><span class="badge bg-success ms-1">Configurado</span><?php endif; ?>
                                </label>
                                <input type="password" name="stripe_webhook_secret" class="form-control" autocomplete="off"
                                       placeholder="<?= $stripeHasWebhookSecret ? '••••••••••' : 'whsec_...' ?>">
                                <div class="form-text">
                                    Configura en Stripe un webhook a <code><?= e(rtrim(config('app.url', ''), '/')) ?>/webhooks/payment/stripe</code>
                                    escuchando el evento <code>checkout.session.completed</code>, y pega aquí su firma.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Concepto que ve el cliente al pagar</label>
                        <input name="payment_concept" class="form-control" value="<?= e($paymentConcept) ?>" placeholder="Digital services">
                        <p class="form-text">Este texto es siempre el mismo, sea lo que sea que esté renovando el cliente (nunca se muestran los días ni el usuario en la pasarela de pago).</p>
                    </div>

                    <label class="form-label">Duraciones rápidas (uso interno)</label>
                    <p class="form-text mt-0">Define las combinaciones de duración + precio que usarás para generar enlaces de pago rápidos desde la ficha de cada cliente. El precio y los días solo se usan internamente para saber cuánto sumar y cuánto se cobró.</p>
                    <table class="table table-sm align-middle" id="presetsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Etiqueta (uso interno)</th>
                                <th style="width:140px;">Días</th>
                                <th style="width:160px;">Precio (EUR)</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($renewalPresets as $preset): ?>
                            <tr>
                                <td><input type="text" name="preset_label[]" class="form-control form-control-sm" value="<?= e($preset['label']) ?>" placeholder="Ej. 1 año"></td>
                                <td><input type="number" min="1" name="preset_days[]" class="form-control form-control-sm" value="<?= (int) $preset['days'] ?>"></td>
                                <td><input type="number" min="0.5" step="0.01" name="preset_price[]" class="form-control form-control-sm" value="<?= e($preset['price']) ?>"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-preset"><i class="bi bi-trash"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="btnAddPreset"><i class="bi bi-plus-lg me-1"></i>Añadir duración</button>
                    <br>
                    <button class="btn btn-primary">Guardar facturación</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="security">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Autenticación de dos factores (2FA)</h6>
                <?php if ($user->two_factor_enabled ?? false): ?>
                <div class="alert alert-success"><i class="bi bi-shield-check me-2"></i>2FA activado</div>
                <?php else: ?>
                <p class="text-muted small">Protege tu cuenta con TOTP (Google Authenticator, Authy, etc.)</p>
                <button class="btn btn-outline-primary" id="btnEnable2fa">Activar 2FA</button>
                <div id="qrcode-area" class="mt-3 d-none">
                    <img id="qr-image" src="" alt="QR Code" class="border rounded">
                    <p class="small mt-2">Escanea el QR e introduce el código:</p>
                    <div class="input-group" style="max-width:200px">
                        <input type="text" id="totp-code" class="form-control" maxlength="6" placeholder="000000">
                        <button class="btn btn-success" id="btnConfirm2fa">Confirmar</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="oauth">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted small">Configura SSO en el archivo <code>.env</code>. Los proveedores habilitados aparecerán en el login.</p>
                <ul class="list-group list-group-flush">
                    <?php foreach (config('oauth', []) as $slug => $provider): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="bi <?= e($provider['icon'] ?? 'bi-key') ?> me-2"></i><?= e($provider['label'] ?? ucfirst($slug)) ?></span>
                        <span class="badge bg-<?= !empty($provider['enabled']) && !empty($provider['client_id']) ? 'success' : 'secondary' ?>">
                            <?= !empty($provider['enabled']) && !empty($provider['client_id']) ? 'Activo' : 'Inactivo' ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <pre class="bg-light p-3 mt-3 small mb-0">OAUTH_GOOGLE_ENABLED=true
OAUTH_GOOGLE_CLIENT_ID=...
OAUTH_GOOGLE_CLIENT_SECRET=...</pre>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
document.getElementById('btnEnable2fa')?.addEventListener('click', async () => {
    const res = await fetch('/settings/2fa/enable', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    const data = await res.json();
    document.getElementById('qrcode-area').classList.remove('d-none');
    document.getElementById('qr-image').src = data.qr_url;
});
document.getElementById('btnConfirm2fa')?.addEventListener('click', async () => {
    const code = document.getElementById('totp-code').value;
    const res = await fetch('/settings/2fa/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ code })
    });
    const data = await res.json();
    alert(data.message || data.error);
    if (data.success) location.reload();
});

document.getElementById('btnAddPreset')?.addEventListener('click', () => {
    const tbody = document.querySelector('#presetsTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="preset_label[]" class="form-control form-control-sm" placeholder="Ej. 1 año"></td>
        <td><input type="number" min="1" name="preset_days[]" class="form-control form-control-sm" value="30"></td>
        <td><input type="number" min="0.5" step="0.01" name="preset_price[]" class="form-control form-control-sm" value="15"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-preset"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
});

document.querySelector('#presetsTable tbody')?.addEventListener('click', (e) => {
    if (e.target.closest('.btn-remove-preset')) {
        e.target.closest('tr').remove();
    }
});

if (location.hash === '#billing') {
    document.querySelector('[data-bs-target="#billing"]')?.click();
}
</script>
JS;
include base_path('resources/views/layouts/app.php');
