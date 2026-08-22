<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">Mensajes a los usuarios</h4>
        <small class="text-muted">Plantillas Telegram enviadas a clientes (caducidad y avisos). Distinto de «Mensajes al detener» (En directo).</small>
    </div>
    <a href="/settings" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>Volver a Configuración</a>
</div>
<?php
$yearPrice = $yearPrice ?? '—';
?>
<p class="text-muted small mb-1">Personaliza los mensajes automáticos por días restantes (positivos = antes de caducar; negativos = días tras caducar). Placeholders: <code><?= e($placeholders) ?></code></p>
<p class="text-muted small">
    Tras caducar: avisos a los <strong>15, 30 y 45 días</strong> para renovar a precio normal
    (<strong><?= e($yearPrice) ?> €/año</strong> según Ajustes → Facturación, sin descuento).
    El reenganche con descuento empieza a los <strong>60 días</strong>.
</p>
<p class="text-muted small">
    <strong>Probar</strong> envía la plantilla <em>guardada</em> al Sandbox Chat ID (siempre sandbox, aunque el modo sandbox esté desactivado),
    con datos de ejemplo (nombre, fecha, días…). Requiere Bot Token + Sandbox Chat ID en
    <a href="/settings#telegram">Configuración → Telegram</a>. Guarda antes si editaste el texto.
</p>

<?php
$milestoneLabel = static function (int $milestone): string {
    if ($milestone === -1) {
        return 'Caducó ayer (−1)';
    }
    if ($milestone < 0) {
        return 'Caducó hace ' . abs($milestone) . ' días (' . $milestone . ')';
    }
    if ($milestone === 0) {
        return 'Caduca hoy (0)';
    }

    return "Faltan {$milestone} días";
};
?>
<form method="POST" action="/settings/notifications">
    <?= csrf_field() ?>
    <div class="accordion" id="msgAccordion">
        <?php foreach ($milestones as $milestone): ?>
        <?php $key = (string) $milestone; $milestone = (int) $milestone; ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#m<?= e(str_replace('-', 'n', $key)) ?>">
                    <?= e($milestoneLabel($milestone)) ?>
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
                    <?php $key = (string) $milestone; $milestone = (int) $milestone; ?>
                    <tr>
                        <td>
                            <?= e($milestoneLabel($milestone)) ?>
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
    'enabled' => true, 'interval_days' => 14, 'max_sends' => 4, 'min_expired_days' => 60,
    'trial_days' => 3, 'discount_percent' => 15, 'link_ttl_days' => 365,
    'invites' => [], 'trial_title' => '', 'trial_body' => '',
];
$reengageInvites = is_array($reengage['invites'] ?? null) ? $reengage['invites'] : [];
$reengageStats = $reengageStats ?? ['contacted' => 0, 'sends' => 0, 'came_back' => 0, 'rate' => 0];
$reengagePlaceholders = $reengagePlaceholders ?? '{username}, {trial_days}, {discount_percent}, {portal_url}';
?>
<div class="card border-0 shadow-sm mt-4" id="reengage">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div>
                <h5 class="mb-1"><i class="bi bi-heart me-1 text-danger"></i>Reenganche de caducados</h5>
                <p class="text-muted small mb-0">
                    Solo a partir de <strong><?= (int) ($reengage['min_expired_days'] ?? 60) ?> días</strong> caducado
                    (antes: renovación a 15/30/45 días a precio de Facturación).
                    Cuatro avisos en orden con enlace de pago Stripe: preset más largo de Facturación
                    con <strong><?= (int) ($reengage['discount_percent'] ?? 15) ?>% de descuento único</strong> por cliente.
                    Si pagan, entran; si no, no pasa nada. El cron manda el siguiente cada <?= (int) $reengage['interval_days'] ?> días.
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
                <div class="col-6 col-md-2">
                    <label class="form-label small">Cada (días)</label>
                    <input type="number" min="1" max="90" name="interval_days" class="form-control form-control-sm" value="<?= (int) $reengage['interval_days'] ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Máx. avisos</label>
                    <input type="number" min="1" max="4" name="max_sends" class="form-control form-control-sm" value="<?= (int) $reengage['max_sends'] ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Caducado mín.</label>
                    <input type="number" min="1" max="180" name="min_expired_days" class="form-control form-control-sm" value="<?= (int) $reengage['min_expired_days'] ?>" title="Días tras caducar antes del 1.er aviso de reenganche (60 ≈ 2 meses)">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Días prueba</label>
                    <input type="number" min="1" max="15" name="trial_days" class="form-control form-control-sm" value="<?= (int) $reengage['trial_days'] ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Descuento %</label>
                    <input type="number" min="0" max="90" name="discount_percent" class="form-control form-control-sm" value="<?= (int) ($reengage['discount_percent'] ?? 15) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Enlace (días)</label>
                    <input type="number" min="30" max="365" name="link_ttl_days" class="form-control form-control-sm" value="<?= (int) ($reengage['link_ttl_days'] ?? 365) ?>">
                </div>
            </div>

            <?php for ($i = 0; $i < 4; $i++): ?>
            <?php
                $inv = $reengageInvites[$i] ?? ['label' => 'Aviso ' . ($i + 1), 'title' => '', 'body' => ''];
                $n = $i + 1;
            ?>
            <div class="border rounded p-3 mb-3 bg-light-subtle">
                <strong class="small d-block mb-2">Aviso <?= $n ?>/4 · <?= e((string) ($inv['label'] ?? '')) ?></strong>
                <label class="form-label small">Título</label>
                <input type="text" name="invite_title_<?= $n ?>" class="form-control form-control-sm mb-2" maxlength="120" value="<?= e((string) ($inv['title'] ?? '')) ?>">
                <label class="form-label small">Texto</label>
                <textarea name="invite_body_<?= $n ?>" class="form-control font-monospace small" rows="8"><?= e((string) ($inv['body'] ?? '')) ?></textarea>
            </div>
            <?php endfor; ?>

            <div class="border rounded p-3 mb-3">
                <strong class="small d-block mb-2">Tras abrir la prueba</strong>
                <label class="form-label small">Título</label>
                <input type="text" name="trial_title" class="form-control form-control-sm mb-2" maxlength="120" value="<?= e($reengage['trial_title']) ?>">
                <label class="form-label small">Texto</label>
                <textarea name="trial_body" class="form-control font-monospace small" rows="7"><?= e($reengage['trial_body']) ?></textarea>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Guardar reenganche</button>
            </div>
        </form>
        <p class="small text-muted mt-3 mb-2">Envía al Sandbox Chat ID la plantilla <em>guardada</em> (guarda antes si editaste). El enlace del mensaje de prueba es de ejemplo.</p>
        <div class="d-flex flex-wrap gap-2">
            <?php for ($n = 1; $n <= 4; $n++): ?>
            <form method="POST" action="/settings/notifications/reengage/test">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="invite">
                <input type="hidden" name="step" value="<?= $n ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">Ver aviso <?= $n ?> en sandbox</button>
            </form>
            <?php endfor; ?>
            <form method="POST" action="/settings/notifications/reengage/test">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="trial">
                <button type="submit" class="btn btn-outline-success btn-sm">Ver prueba abierta en sandbox</button>
            </form>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
