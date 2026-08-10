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
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
