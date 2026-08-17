<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Configuración</h4>
    <div class="d-flex flex-wrap gap-2">
        <a href="/settings/notifications" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-chat-dots me-1"></i>Mensajes a los usuarios
        </a>
        <a href="/settings/stop-messages" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chat-left-text me-1"></i>Mensajes al detener
        </a>
    </div>
</div>

<div class="alert alert-light border mb-4">
    <div class="fw-semibold mb-1">Mensajes</div>
    <ul class="small mb-0">
        <li><a href="/settings/notifications">Mensajes a los usuarios</a> — plantillas Telegram de caducidad; cada una tiene <strong>Probar</strong> al sandbox.</li>
        <li><a href="/settings/stop-messages">Mensajes al detener</a> — textos al cortar una sesión en En directo; también con <strong>Probar en sandbox</strong>.</li>
    </ul>
</div>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#general">General</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#smtp">Email / SMTP</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#telegram">Telegram</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#whatsapp">WhatsApp / Alertas admin</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#peticiones">Peticiones / BD remota</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#billing">Facturación</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#cron">Cron / Tareas</button></li>
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
                    <div class="mb-3">
                        <label class="form-label">Bot Token</label>
                        <input name="telegram_bot_token" class="form-control" value="<?= e($settings['telegram_bot_token'] ?? '') ?>" autocomplete="off">
                        <div class="form-text">También se puede definir en <code>.env</code> como <code>TELEGRAM_BOT_TOKEN</code>. Si hay valor aquí, tiene prioridad.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Chat ID del admin (alertas)</label>
                        <input name="telegram_chat_id" class="form-control" value="<?= e($settings['telegram_chat_id'] ?? '') ?>" placeholder="Ej. 123456789">
                        <div class="form-text">
                            Donde llegan avisos del panel (servidor caído, altas/renovaciones, automatizaciones admin). No es el chat de cada cliente.
                            Al caer un servidor también se envía email (pestaña Cron) y WhatsApp (pestaña WhatsApp / Alertas admin) si CallMeBot está configurado.
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="mb-2"><i class="bi bi-shield-exclamation me-1"></i>Sandbox (pruebas de mensajes a usuarios)</h6>
                    <p class="small text-muted">Con sandbox activo, los mensajes enviados a usuarios (ficha, masivo, caducidad) van a tu chat de prueba en lugar del cliente. Así puedes probar textos sin molestar a nadie.</p>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="telegram_sandbox_enabled" value="1" id="tgSandbox"
                               <?= !empty($settings['telegram_sandbox_enabled']) && $settings['telegram_sandbox_enabled'] !== '0' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tgSandbox">Activar modo sandbox</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sandbox Chat ID</label>
                        <input name="telegram_sandbox_chat_id" class="form-control" value="<?= e($settings['telegram_sandbox_chat_id'] ?? '') ?>"
                               placeholder="Tu Chat ID de Telegram para pruebas">
                        <div class="form-text">También se puede definir en <code>.env</code> como <code>TELEGRAM_SANDBOX_CHAT_ID</code>. Activa sandbox con el switch o <code>TELEGRAM_SANDBOX=true</code>.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="telegram_sandbox_copy_real" value="1" id="tgSandboxCopy"
                               <?= !empty($settings['telegram_sandbox_copy_real']) && $settings['telegram_sandbox_copy_real'] !== '0' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tgSandboxCopy">Además, enviar copia al usuario real</label>
                    </div>
                    <button class="btn btn-primary">Guardar Telegram</button>
                </form>

                <hr class="my-4">
                <h6 class="mb-2"><i class="bi bi-send me-1"></i>Probar envío</h6>
                <p class="small text-muted mb-3">
                    Envía un mensaje corto con el bot configurado al chat sandbox (si sandbox está activo)
                    o al Chat ID del admin. Útil para comprobar token y que Telegram llega a tu móvil.
                </p>
                <form method="POST" action="/settings/telegram/test" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-telegram me-1"></i>Enviar mensaje de prueba
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="whatsapp">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-whatsapp me-1"></i>Alertas WhatsApp (admin)</h6>
                <p class="small text-muted mb-3">
                    Canal CallMeBot para avisos al admin: <strong>altas</strong>, <strong>renovaciones</strong> y
                    <strong>servidor caído</strong> (este último también por Telegram + email).
                    Si acabas de pedir el apikey a CallMeBot, la espera de ~24&nbsp;h es normal: pega el apikey aquí cuando llegue.
                </p>
                <form method="POST" action="/settings" class="row g-3" id="whatsappAlertsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="whatsapp">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="whatsapp_enabled" value="1" id="waAlerts"
                                   <?= !empty($settings['whatsapp_enabled']) && $settings['whatsapp_enabled'] !== '0' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="waAlerts">Activar alertas WhatsApp (CallMeBot)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Teléfono WhatsApp (con prefijo país)</label>
                        <input name="whatsapp_phone" class="form-control" autocomplete="off"
                               value="<?= e($settings['whatsapp_phone'] ?? '') ?>" placeholder="346xxxxxxxx">
                        <div class="form-text">Sin espacios. Ejemplo España: <code>34612345678</code>. .env: <code>WHATSAPP_CALLMEBOT_PHONE</code></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">API key CallMeBot</label>
                        <input type="password" name="whatsapp_apikey" class="form-control" autocomplete="off"
                               placeholder="<?= !empty($settings['whatsapp_apikey']) ? '••••••••••' : 'apikey de CallMeBot' ?>">
                        <div class="form-text">Déjala en blanco para no cambiar la guardada. .env: <code>WHATSAPP_CALLMEBOT_APIKEY</code></div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-light border small mb-0">
                            <strong>Cómo obtener el apikey (gratis):</strong>
                            <ol class="mb-0 mt-1 ps-3">
                                <li>Añade el contacto de CallMeBot (ver <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener">instrucciones</a>).</li>
                                <li>Envíale el mensaje: <em>I allow callmebot to send me messages</em>.</li>
                                <li>Te responden con el apikey (a veces tarda hasta ~24&nbsp;h). Pégalo arriba y guarda.</li>
                            </ol>
                        </div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">Guardar WhatsApp</button>
                        <button type="submit" class="btn btn-outline-success" formaction="/settings/whatsapp/test">
                            <i class="bi bi-whatsapp me-1"></i>Probar WhatsApp
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-2">Qué avisos llegan aquí</h6>
                <ul class="small text-muted mb-0">
                    <li><strong>Alta:</strong> registro <code>/registro</code>, crear usuario en el panel, invitación rápida del dashboard.</li>
                    <li><strong>Renovación:</strong> registro (usuario existente), pago Stripe OK, extensión manual (+días).</li>
                    <li><strong>Servidor caído:</strong> mismo CallMeBot; email de alertas se configura en Cron.</li>
                    <li>Un mensaje por evento (sin spam de reintentos en altas/renovaciones).</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="peticiones">
        <?php $pet = $peticiones ?? []; ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-film me-1"></i>Peticiones / BD remota</h6>
                <p class="small text-muted mb-3">
                    Conexión a la misma MySQL del panel legacy (<code>peticiones</code> / <code>motivo</code>).
                    El panel viejo sigue en paralelo. La contraseña se guarda cifrada (SecretCrypt), nunca en Git.
                    Documentación: <code>docs/PETICIONES.md</code>.
                </p>
                <form method="POST" action="/settings" class="row g-3" id="peticionesDbForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="peticiones">
                    <div class="col-md-8">
                        <label class="form-label">Host</label>
                        <input name="peticiones_db_host" class="form-control" autocomplete="off"
                               value="<?= e($pet['peticiones_db_host'] ?? '') ?>" placeholder="servidor.ejemplo.net">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Puerto</label>
                        <input name="peticiones_db_port" type="number" class="form-control" min="1" max="65535"
                               value="<?= e($pet['peticiones_db_port'] ?? '3306') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Base de datos</label>
                        <input name="peticiones_db_database" class="form-control" autocomplete="off"
                               value="<?= e($pet['peticiones_db_database'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Usuario</label>
                        <input name="peticiones_db_username" class="form-control" autocomplete="off"
                               value="<?= e($pet['peticiones_db_username'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contraseña</label>
                        <input type="password" name="peticiones_db_password" class="form-control" autocomplete="new-password"
                               placeholder="<?= !empty($pet['peticiones_db_password_set']) ? '•••••••••• (dejar vacío = no cambiar)' : '' ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">TMDb API key (opcional)</label>
                        <input type="password" name="peticiones_tmdb_api_key" class="form-control" autocomplete="off"
                               placeholder="<?= !empty($pet['peticiones_tmdb_api_key_set']) ? '•••••••••• (dejar vacío = no cambiar)' : 'Vacío = sin plataformas' ?>">
                        <div class="form-text">Solo para mostrar plataformas de streaming. No pegues claves antiguas expuestas.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ScraperAPI key (opcional, futuro)</label>
                        <input type="password" name="peticiones_scraper_api_key" class="form-control" autocomplete="off"
                               placeholder="<?= !empty($pet['peticiones_scraper_api_key_set']) ? '•••••••••• (dejar vacío = no cambiar)' : 'Sin scrape automático en MVP' ?>">
                    </div>
                    <div class="col-12">
                        <div class="alert alert-light border small mb-0">
                            <strong>Firewall:</strong> MySQL en el host remoto debe aceptar conexiones desde la IP del VPS MultiPanel al puerto 3306
                            (el legacy usaba <code>localhost</code> en esa máquina; MultiPanel conecta por red).
                        </div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">Guardar BD remota</button>
                        <button type="submit" class="btn btn-outline-success" formaction="/settings/peticiones/test">
                            <i class="bi bi-plug me-1"></i>Probar conexión
                        </button>
                        <a href="/peticiones" class="btn btn-outline-secondary">Ir a Peticiones</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="cron">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-2">Token de seguridad</h6>
                <p class="small text-muted">Obligatorio para las URLs HTTP. También puedes poner <code>CRON_TOKEN=...</code> en <code>.env</code> (prioridad sobre este valor).</p>
                <form method="POST" action="/settings" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="cron">
                    <div class="col-md-8">
                        <label class="form-label">CRON_TOKEN</label>
                        <input type="text" name="cron_token" class="form-control font-monospace" autocomplete="off"
                               placeholder="<?= !empty($cronTokenConfigured) ? e($cronTokenMasked) : 'genera-un-secreto-largo' ?>">
                        <div class="form-text">Déjalo en blanco para no cambiar el actual.</div>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100">Guardar token</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-clock-history me-1"></i>Avisos de caducidad (hora)</h6>
                <p class="small text-muted">
                    Aunque el cron <code>all</code> corra cada 5 minutos, los mensajes de caducidad a usuarios
                    <strong>solo se envían</strong> en la hora local configurada (por defecto 09:00 Europe/Madrid).
                    Fuera de esa hora se omite el envío y <em>no</em> se marca como enviado.
                </p>
                <form method="POST" action="/settings" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="cron">
                    <div class="col-md-3">
                        <label class="form-label">Hora (0–23)</label>
                        <input type="number" min="0" max="23" name="expiry_notify_hour" class="form-control"
                               value="<?= e($settings['expiry_notify_hour'] ?? (string) config('expiry_notifications.notify_hour', 9)) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Zona horaria</label>
                        <input name="expiry_notify_timezone" class="form-control"
                               value="<?= e($settings['expiry_notify_timezone'] ?? (string) config('expiry_notifications.notify_timezone', 'Europe/Madrid')) ?>"
                               placeholder="Europe/Madrid">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Ventana (minutos)</label>
                        <input type="number" min="1" max="60" name="expiry_notify_window_minutes" class="form-control"
                               value="<?= e($settings['expiry_notify_window_minutes'] ?? (string) config('expiry_notifications.notify_window_minutes', 15)) ?>">
                        <div class="form-text">Preferido 15 → 09:00–09:14 con cron */5.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Guardar hora de caducidad</button>
                        <span class="form-text ms-2"><code>EXPIRY_NOTIFY_HOUR</code> / <code>EXPIRY_NOTIFY_TIMEZONE</code> en .env</span>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Alertas servidor caído (email)</h6>
                <p class="small text-muted mb-3">
                    Al detectar un servidor offline: diagnóstico HTTP/DNS + Telegram admin + este email + WhatsApp opcional.
                    Reavisos a los 5 / 15 / 30 minutos si sigue caído (luego no spam).
                    Teléfono y API key de WhatsApp están en la pestaña
                    <a href="#whatsapp" data-bs-toggle="tab" data-bs-target="#whatsapp">WhatsApp / Alertas admin</a>.
                </p>
                <form method="POST" action="/settings" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="alerts">
                    <div class="col-md-8">
                        <label class="form-label">Email de alertas admin</label>
                        <input type="email" name="alert_email" class="form-control"
                               value="<?= e($settings['alert_email'] ?? (string) config('alerts.email', 'alex@masquecero.es')) ?>"
                               placeholder="alex@masquecero.es">
                        <div class="form-text">Default / .env: <code>ALERT_EMAIL</code>. Requiere SMTP en la pestaña Email.</div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Guardar email de alertas</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-2">CLI (recomendado en VPS)</h6>
                <pre class="bg-light p-3 small mb-2">*/5 * * * * php <?= e($cronCliBase) ?> all >> /var/log/multipanel-cron.log 2>&amp;1
0 3 * * * php <?= e($cronCliBase) ?> backup
# expiry va en «all»; solo envía ~09:00 Europe/Madrid (no hace falta cron aparte)</pre>
                <p class="small text-muted mb-0">Ruta absoluta del script: <code><?= e($cronCliBase) ?></code></p>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-2">URLs HTTP (Plesk / cron web)</h6>
                <p class="small text-muted">Sustituye <code>TU_TOKEN</code> por el CRON_TOKEN. Si <code>APP_URL</code> no está bien, usa rutas relativas bajo tu dominio.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Tarea</th>
                                <th>Qué hace</th>
                                <th>Programación sugerida</th>
                                <th>URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cronCatalog as $task => $meta): ?>
                            <tr>
                                <td><code><?= e($task) ?></code><br><span class="small"><?= e($meta['title']) ?></span></td>
                                <td class="small"><?= e($meta['description']) ?></td>
                                <td class="small text-nowrap"><?= e($meta['schedule']) ?></td>
                                <td class="small">
                                    <code class="user-select-all"><?= e(($cronHttpBase !== '/cron/run' ? $cronHttpBase : '/cron/run') . ($task === 'all' ? '' : '/' . $task) . '?token=TU_TOKEN') ?></code>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="billing">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings/billing" id="billingForm">
                    <?= csrf_field() ?>

                    <div class="mb-4 p-3 border rounded bg-light">
                        <h6 class="d-flex align-items-center gap-2 flex-wrap">
                            <i class="bi bi-credit-card-2-front text-primary"></i>Conexión con Stripe
                            <?php if ($stripeHasSecretKey): ?>
                            <span class="badge bg-success">Configurado · <?= e($stripeMode) ?></span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Sin configurar · <?= e($stripeMode) ?></span>
                            <?php endif; ?>
                            <?php if ($stripeMode === 'live'): ?>
                            <span class="badge bg-danger">Modo Live activo</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Modo Test activo</span>
                            <?php endif; ?>
                        </h6>
                        <p class="form-text mt-0">
                            Guarda las claves de <strong>Test</strong> y <strong>Live</strong> a la vez y cambia el modo sin volver a pegarlas.
                            Checkout y «Probar conexión» usan siempre el <strong>modo activo</strong>.
                            Claves en <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/apikeys</a>
                            (cambia el interruptor Test/Live del propio Stripe para copiar cada juego).
                        </p>
                        <?php if (!empty($appUrlLooksLocal)): ?>
                        <div class="alert alert-warning py-2 small mb-3">
                            <strong>APP_URL parece localhost</strong> (<code><?= e($appUrl !== '' ? $appUrl : '(vacío)') ?></code>).
                            En el servidor pon en <code>.env</code> tu dominio real con HTTPS, por ejemplo
                            <code>APP_URL=https://tudominio.com</code>, y reinicia PHP-FPM. Si no, Stripe puede rechazar el checkout (sobre todo con claves live).
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Modo activo</label>
                            <div class="btn-group" role="group" aria-label="Modo Stripe">
                                <input type="radio" class="btn-check" name="stripe_mode" id="stripeModeTest" value="test"
                                       <?= $stripeMode === 'test' ? 'checked' : '' ?> autocomplete="off">
                                <label class="btn btn-outline-secondary" for="stripeModeTest">Test</label>
                                <input type="radio" class="btn-check" name="stripe_mode" id="stripeModeLive" value="live"
                                       <?= $stripeMode === 'live' ? 'checked' : '' ?> autocomplete="off">
                                <label class="btn btn-outline-danger" for="stripeModeLive">Live</label>
                            </div>
                            <div class="form-text">Al guardar, este modo queda activo para nuevos checkouts. Los webhooks aceptan firma de ambos secretos (activo primero).</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100 <?= $stripeMode === 'test' ? 'border-primary' : '' ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <strong><i class="bi bi-flask me-1"></i>Claves Test</strong>
                                        <?php if (!empty($stripeTest['has_secret'])): ?>
                                        <span class="badge bg-success">sk_test guardada</span>
                                        <?php else: ?>
                                        <span class="badge bg-light text-dark border">Vacío</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Secret key (<code>sk_test_</code>)</label>
                                        <input type="password" name="stripe_secret_key_test" class="form-control form-control-sm" autocomplete="off"
                                               placeholder="<?= !empty($stripeTest['has_secret']) ? e($stripeTest['secret_masked']) : 'sk_test_...' ?>">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Publishable key (<code>pk_test_</code>)</label>
                                        <input type="text" name="stripe_publishable_key_test" class="form-control form-control-sm" autocomplete="off"
                                               value="<?= e($stripeTest['publishable'] ?? '') ?>" placeholder="pk_test_...">
                                    </div>
                                    <div>
                                        <label class="form-label small">
                                            Webhook secret (<code>whsec_</code>)
                                            <?php if (!empty($stripeTest['has_webhook'])): ?><span class="badge bg-success ms-1">OK</span><?php endif; ?>
                                        </label>
                                        <input type="password" name="stripe_webhook_secret_test" class="form-control form-control-sm" autocomplete="off"
                                               placeholder="<?= !empty($stripeTest['has_webhook']) ? '••••••••••' : 'whsec_...' ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100 <?= $stripeMode === 'live' ? 'border-danger' : '' ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <strong><i class="bi bi-lightning-charge me-1"></i>Claves Live</strong>
                                        <?php if (!empty($stripeLive['has_secret'])): ?>
                                        <span class="badge bg-success">sk_live guardada</span>
                                        <?php else: ?>
                                        <span class="badge bg-light text-dark border">Vacío</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Secret key (<code>sk_live_</code>)</label>
                                        <input type="password" name="stripe_secret_key_live" class="form-control form-control-sm" autocomplete="off"
                                               placeholder="<?= !empty($stripeLive['has_secret']) ? e($stripeLive['secret_masked']) : 'sk_live_...' ?>">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Publishable key (<code>pk_live_</code>)</label>
                                        <input type="text" name="stripe_publishable_key_live" class="form-control form-control-sm" autocomplete="off"
                                               value="<?= e($stripeLive['publishable'] ?? '') ?>" placeholder="pk_live_...">
                                    </div>
                                    <div>
                                        <label class="form-label small">
                                            Webhook secret (<code>whsec_</code>)
                                            <?php if (!empty($stripeLive['has_webhook'])): ?><span class="badge bg-success ms-1">OK</span><?php endif; ?>
                                        </label>
                                        <input type="password" name="stripe_webhook_secret_live" class="form-control form-control-sm" autocomplete="off"
                                               placeholder="<?= !empty($stripeLive['has_webhook']) ? '••••••••••' : 'whsec_...' ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-text mt-3 mb-0">
                            <strong>Webhooks:</strong> registra el mismo endpoint en el dashboard Test y en el Live:
                            <code><?= e(($appUrl !== '' ? rtrim($appUrl, '/') : 'https://TU-DOMINIO')) ?>/webhooks/payment/stripe</code>
                            escuchando <code>checkout.session.completed</code>.
                            Pega aquí <em>ambos</em> Signing secrets (<code>whsec_</code>); MultiPanel verifica primero el del modo activo y, si falla, el otro.
                            Campos vacíos no borran la clave ya guardada.
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-outline-primary btn-sm" formaction="/settings/stripe/test"
                                    title="Comprueba la secret key del modo activo contra la API de Stripe (no crea cobros)">
                                <i class="bi bi-plug me-1"></i>Probar conexión Stripe
                            </button>
                            <span class="form-text ms-2">Usa la secret del modo activo (guardada o pegada arriba sin guardar).</span>
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

const hashTab = location.hash.replace('#', '');
if (hashTab) {
    document.querySelector(`[data-bs-target="#${hashTab}"]`)?.click();
}
</script>
JS;
include base_path('resources/views/layouts/app.php');
