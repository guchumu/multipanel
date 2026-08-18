<?php
$filterStatus = $filterStatus ?? '';
$filterChannel = $filterChannel ?? '';
ob_start();
?>
<div class="mb-4">
    <a href="/media-users/<?= e($mediaUser->uuid) ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver a la ficha</a>
    <h4 class="mt-2">Historial de avisos</h4>
    <p class="text-muted small mb-0"><?= e($mediaUser->display_name ?? $mediaUser->username) ?> · <?= e($mediaUser->email ?? '') ?></p>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Enviar mensaje</strong>
        <a href="/media-users/<?= e($mediaUser->uuid) ?>" class="small">Abrir ficha del usuario</a>
    </div>
    <div class="card-body">
        <input type="text" id="msgTitle" class="form-control form-control-sm mb-2" value="Aviso" placeholder="Título">
        <textarea id="msgBody" class="form-control form-control-sm mb-2" rows="5" placeholder="Mensaje…"></textarea>
        <p class="small text-muted mb-2">Variables: {username}, {email}, {display_name}, {end_date}</p>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-info btn-sm flex-fill" id="btnSendMsg" <?= $mediaUser->telegram_chat_id ? '' : 'disabled title="El usuario no tiene Telegram configurado"' ?>>
                <i class="bi bi-telegram me-1"></i>Telegram
            </button>
            <button type="button" class="btn btn-outline-success btn-sm flex-fill" id="btnSendMsgWhatsapp">
                <i class="bi bi-whatsapp me-1"></i>WhatsApp
            </button>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/media-users/<?= e($mediaUser->uuid) ?>/messages" class="d-flex flex-wrap gap-2 align-items-center">
            <label class="small text-muted mb-0">Estado</label>
            <select name="status" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="sent" <?= $filterStatus === 'sent' ? 'selected' : '' ?>>Enviados</option>
                <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Fallidos</option>
            </select>
            <label class="small text-muted mb-0">Canal</label>
            <select name="channel" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="telegram" <?= $filterChannel === 'telegram' ? 'selected' : '' ?>>Telegram</option>
                <option value="whatsapp" <?= $filterChannel === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
            </select>
            <?php if ($filterStatus !== '' || $filterChannel !== ''): ?>
            <a href="/media-users/<?= e($mediaUser->uuid) ?>/messages" class="btn btn-link btn-sm">Quitar filtros</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Canal</th>
                    <th>Tipo</th>
                    <th>Título / cuerpo</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Sin avisos registrados</td></tr>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <?php
                    $failed = ($msg['status'] ?? '') === 'failed';
                    $title = trim((string) ($msg['title'] ?? ''));
                    $body = trim((string) ($msg['body'] ?? ''));
                ?>
                <tr class="<?= $failed ? 'table-danger' : '' ?>">
                    <td class="small text-nowrap"><?= e($msg['sent_at'] ?? '') ?></td>
                    <td class="small"><?= e($msg['channel'] ?? '—') ?></td>
                    <td><span class="badge bg-secondary"><?= e($msg['message_type'] ?? '') ?></span></td>
                    <td class="small" style="max-width: 420px;">
                        <?php if ($title !== ''): ?>
                        <div class="fw-medium"><?= e($title) ?></div>
                        <?php endif; ?>
                        <div style="white-space: pre-wrap;"><?= e(mb_substr($body, 0, 280)) ?><?= mb_strlen($body) > 280 ? '…' : '' ?></div>
                    </td>
                    <td>
                        <span class="badge bg-<?= $failed ? 'danger' : 'success' ?>">
                            <?= $failed ? 'fallido' : 'enviado' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if ($failed && ($msg['channel'] ?? '') === 'telegram'): ?>
                        <button type="button"
                                class="btn btn-outline-danger btn-sm btn-retry-msg"
                                data-msg-id="<?= (int) ($msg['id'] ?? 0) ?>"
                                title="Reintentar envío">
                            <i class="bi bi-arrow-repeat me-1"></i>Reintentar
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
$scripts = '<script>window.MEDIA_USER_UUID = ' . json_encode($mediaUser->uuid) . ';';
$scripts .= 'window.MEDIA_USER_WHATSAPP = ' . json_encode($mediaUser->metaGet('whatsapp_phone')) . ';</script>';
$scripts .= '<script src="' . e(asset('js/media-user-show.js')) . '"></script>';
include base_path('resources/views/layouts/app.php');
?>
