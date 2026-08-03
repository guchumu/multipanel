<?php ob_start(); ?>
<h4 class="mb-4">Mensajes Telegram — avisos de caducidad</h4>
<p class="text-muted small">Personaliza los mensajes automáticos por días restantes. Placeholders: <code><?= e($placeholders) ?></code></p>

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
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
