<?php
/** @var array<int, array<string, mixed>> $messages */
/** @var string $videoTranscodeMessage */
/** @var string $videoTranscodeDefault */
/** @var string $effectiveVideoTranscodeMessage */
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">Mensajes al detener</h4>
        <small class="text-muted">Textos que ve el usuario en el reproductor al cortar (manual, límites o Transcode).</small>
    </div>
    <a href="/activity" class="btn btn-outline-secondary btn-sm"><i class="bi bi-broadcast-pin me-1"></i>Ir a En directo</a>
</div>

<p class="text-muted small">
    <strong>Probar en sandbox</strong> envía el texto a tu Sandbox Chat ID por Telegram (solo para ver cómo queda;
    no afecta al reproductor). Requiere Bot Token + Sandbox Chat ID en
    <a href="/settings#telegram">Configuración → Telegram</a>. Guarda antes si editaste.
</p>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6 class="mb-1"><i class="bi bi-cpu me-1"></i>Auto-corte Transcode (calidad / ajustes)</h6>
        <p class="small text-muted mb-3">
            Se usa en «Cortar ahora» y en el auto-corte de En directo. Vacío = texto por defecto del sistema.
            Máx. 500 caracteres.
        </p>
        <form method="POST" action="/settings/stop-messages/video-transcode">
            <?= csrf_field() ?>
            <div class="mb-2">
                <label class="form-label" for="kill_message_video_transcode">Mensaje al cliente</label>
                <textarea class="form-control" id="kill_message_video_transcode" name="kill_message_video_transcode"
                          rows="4" maxlength="500"
                          placeholder="<?= e($videoTranscodeDefault) ?>"><?= e($videoTranscodeMessage) ?></textarea>
            </div>
            <?php if ($videoTranscodeMessage === ''): ?>
            <details class="mb-3">
                <summary class="small text-muted">Ver texto por defecto que se enviará ahora</summary>
                <p class="small mt-2 mb-0 p-2 bg-light rounded"><?= e($effectiveVideoTranscodeMessage) ?></p>
            </details>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Guardar mensaje Transcode
                </button>
            </div>
        </form>
        <form method="POST" action="/settings/stop-messages/video-transcode/test" class="mt-2">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-telegram me-1"></i>Probar en sandbox (texto efectivo)
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-3">Nuevo mensaje (corte manual)</h6>
                <form method="POST" action="/settings/stop-messages">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input name="title" class="form-control" maxlength="120" placeholder="Ej. Aviso de configuración" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mensaje</label>
                        <textarea name="body" class="form-control" rows="4" maxlength="500" required
                                  placeholder="Texto que verá el usuario al cortar la reproducción"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_default" value="1" id="newDefault">
                        <label class="form-check-label" for="newDefault">Usar como predeterminado</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Crear mensaje</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-3">Mensajes guardados (corte manual en En directo)</h6>
                <?php if (empty($messages)): ?>
                <p class="text-muted mb-0">No hay mensajes. Se creará el predeterminado al usar En directo.</p>
                <?php else: ?>
                <div class="vstack gap-3">
                    <?php foreach ($messages as $msg): ?>
                    <div class="border rounded p-3">
                        <form method="POST" action="/settings/stop-messages/<?= (int) $msg['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_method" value="PUT">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                                <div class="flex-grow-1" style="min-width: 200px;">
                                    <label class="form-label small mb-1">Título</label>
                                    <input name="title" class="form-control form-control-sm" maxlength="120"
                                           value="<?= e($msg['title']) ?>" required>
                                </div>
                                <div class="pt-4">
                                    <?php if ((int) $msg['is_default'] === 1): ?>
                                    <span class="badge bg-primary">Predeterminado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1">Mensaje</label>
                                <textarea name="body" class="form-control form-control-sm" rows="3" maxlength="500" required><?= e($msg['body']) ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <div class="form-check me-auto">
                                    <input class="form-check-input" type="checkbox" name="is_default" value="1"
                                           id="def<?= (int) $msg['id'] ?>"
                                           <?= (int) $msg['is_default'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="def<?= (int) $msg['id'] ?>">Predeterminado</label>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                            </div>
                        </form>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <form method="POST" action="/settings/stop-messages/<?= (int) $msg['id'] ?>/test">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-telegram me-1"></i>Probar en sandbox
                                </button>
                            </form>
                            <?php if ((int) $msg['is_default'] !== 1): ?>
                            <form method="POST" action="/settings/stop-messages/<?= (int) $msg['id'] ?>/default">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Marcar predeterminado</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="/settings/stop-messages/<?= (int) $msg['id'] ?>"
                                  onsubmit="return confirm('¿Eliminar este mensaje?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
