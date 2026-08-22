<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">Mensajes a los usuarios</h4>
        <small class="text-muted">Plantillas Telegram enviadas a clientes (caducidad y avisos). Distinto de «Mensajes al detener» (En directo).</small>
    </div>
    <a href="/settings" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>Volver a Configuración</a>
</div>
<p class="text-muted small mb-1">Personaliza los mensajes automáticos por días restantes. Placeholders: <code><?= e($placeholders) ?></code></p>
<p class="text-muted small">
    <strong>Probar</strong> envía la plantilla <em>guardada</em> al Sandbox Chat ID (siempre sandbox, aunque el modo sandbox esté desactivado),
    con datos de ejemplo (nombre, fecha, días…). Requiere Bot Token + Sandbox Chat ID en
    <a href="/settings#telegram">Configuración → Telegram</a>. Guarda antes si editaste el texto.
</p>

<form method="POST" action="/settings/notifications">
    <?= csrf_field() ?>
    <div class="accordion" id="msgAccordion">
        <?php foreach ($milestones as $milestone): ?>
        <?php $key = (string) $milestone; ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#m<?= e(str_replace('-', 'n', $key)) ?>">
                    <?= $milestone === -1 ? 'Caducó ayer (-1)' : ($milestone === 0 ? 'Caduca hoy (0)' : "Faltan {$milestone} días") ?>
                </button>
            </h2>
            <div id="m<?= e(str_replace('-', 'n', $key)) ?>" class="accordion-collapse collapse" data-bs-parent="#msgAccordion">
                <div class="accordion-body">
                    <textarea name="message_<?= e($key) ?>" class="form-control font-monospace small" rows="8"><?= e($messages[$milestone] ?? $messages[$key] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-primary mt-3">Guardar plantillas</button>
</form>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-telegram me-1"></i>Probar en sandbox</h6>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tipo de aviso</th>
                        <th class="text-end" style="width: 140px;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($milestones as $milestone): ?>
                    <?php $key = (string) $milestone; ?>
                    <tr>
                        <td>
                            <?= $milestone === -1 ? 'Caducó ayer (-1)' : ($milestone === 0 ? 'Caduca hoy (0)' : "Faltan {$milestone} días") ?>
                        </td>
                        <td class="text-end">
                            <form method="POST" action="/settings/notifications/test" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="milestone" value="<?= e($key) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    Probar
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$reengage = $reengage ?? [
    'enabled' => true, 'interval_days' => 14, 'max_sends' => 4, 'min_expired_days' => 3,
    'trial_days' => 3, 'title' => '', 'body' => '', 'trial_title' => '', 'trial_body' => '',
];
$reengageStats = $reengageStats ?? ['contacted' => 0, 'sends' => 0, 'came_back' => 0, 'rate' => 0];
$reengagePlaceholders = $reengagePlaceholders ?? '{username}, {trial_days}, {portal_url}';
?>
<div class="card border-0 shadow-sm mt-4" id="reengage">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div>
                <h5 class="mb-1"><i class="bi bi-heart me-1 text-danger"></i>Reenganche de caducados</h5>
                <p class="text-muted small mb-0">
                    Mensaje para invitar a volver o abrir una prueba corta. Si no responden, el cron de las 09:00 lo
                    vuelve a enviar cada <?= (int) $reengage['interval_days'] ?> días (tope <?= (int) $reengage['max_sends'] ?> avisos).
                    Si vuelven (renuevan más allá de la prueba), se deja de escribir.
                </p>
            </div>
            <span class="badge bg-light text-dark border">
                <?= (int) $reengageStats['came_back'] ?> volvieron
                · <?= (int) $reengageStats['contacted'] ?> contactados
                <?php if ((int) $reengageStats['contacted'] > 0): ?>
                (<?= (int) $reengageStats['rate'] ?>%)
                <?php endif; ?>
            </span>
        </div>
        <p class="small text-muted">Placeholders: <code><?= e($reengagePlaceholders) ?></code></p>
        <form method="POST" action="/settings/notifications/reengage">
            <?= csrf_field() ?>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="reengageEnabled" name="enabled" value="1" <?= !empty($reengage['enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="reengageEnabled">Enviar automáticamente (cron 09:00)</label>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <label class="form-label small">Cada (días)</label>
                    <input type="number" min="1" max="90" name="interval_days" class="form-control form-control-sm" value="<?= (int) $reengage['interval_days'] ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small">Máximo de avisos</label>
                    <input type="number" min="1" max="12" name="max_sends" class="form-control form-control-sm" value="<?= (int) $reengage['max_sends'] ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small">Días caducado mínimo</label>
                    <input type="number" min="1" max="60" name="min_expired_days" class="form-control form-control-sm" value="<?= (int) $reengage['min_expired_days'] ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small">Días de prueba</label>
                    <input type="number" min="1" max="15" name="trial_days" class="form-control form-control-sm" value="<?= (int) $reengage['trial_days'] ?>">
                </div>
            </div>
            <label class="form-label small">Título · invitar a volver</label>
            <input type="text" name="title" class="form-control form-control-sm mb-2" maxlength="120" value="<?= e($reengage['title']) ?>">
            <label class="form-label small">Texto · invitar a volver</label>
            <textarea name="body" class="form-control font-monospace small mb-3" rows="8"><?= e($reengage['body']) ?></textarea>
            <label class="form-label small">Título · prueba abierta</label>
            <input type="text" name="trial_title" class="form-control form-control-sm mb-2" maxlength="120" value="<?= e($reengage['trial_title']) ?>">
            <label class="form-label small">Texto · tras abrir la prueba</label>
            <textarea name="trial_body" class="form-control font-monospace small" rows="7"><?= e($reengage['trial_body']) ?></textarea>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Guardar reenganche</button>
            </div>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <form method="POST" action="/settings/notifications/reengage/test">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="invite">
                <button type="submit" class="btn btn-outline-primary btn-sm">Probar invitación</button>
            </form>
            <form method="POST" action="/settings/notifications/reengage/test">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="trial">
                <button type="submit" class="btn btn-outline-success btn-sm">Probar mensaje de prueba</button>
            </form>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
