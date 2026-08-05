<?php
$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'active' => 'bg-success',
        'suspended' => 'bg-warning text-dark',
        'pending' => 'bg-secondary',
        default => 'bg-light text-dark border',
    };
};
$membershipBadge = static function ($onServer): array {
    if ($onServer === null || $onServer === '') {
        return ['label' => 'Sin sync', 'class' => 'bg-light text-dark border', 'hint' => 'Pulsa Forzar sincronización para comprobar si está en el servidor.'];
    }
    if ((int) $onServer === 1) {
        return ['label' => 'En biblioteca', 'class' => 'bg-success', 'hint' => 'Aparece en la lista de usuarios del servidor.'];
    }
    return ['label' => 'No está en el servidor', 'class' => 'bg-danger', 'hint' => 'Tenía external_id en el panel pero ya no aparece en Plex/Jellyfin.'];
};
$mb = $membershipBadge($mediaUser->on_server ?? null);
$playMethodLabel = static function (string $method): string {
    return match ($method) {
        'direct_play' => 'Direct Play',
        'direct_stream' => 'Direct Stream',
        'transcode' => 'Transcode',
        default => ucfirst(str_replace('_', ' ', $method)),
    };
};
$playMethodBadge = static function (string $method): string {
    return match ($method) {
        'direct_play' => 'success',
        'direct_stream' => 'info',
        'transcode' => 'warning',
        default => 'secondary',
    };
};
ob_start();
?>
<div class="mb-4">
    <a href="/media-users" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver a usuarios</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2">
        <div>
            <h4 class="mb-1"><?= e($mediaUser->display_name ?? $mediaUser->username) ?></h4>
            <p class="text-muted small mb-0">ID <?= (int) $mediaUser->id ?> · <?= e($mediaUser->email ?? '-') ?> · <?= e($mediaUser->server_name ?? 'Sin servidor') ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge <?= e($statusBadgeClass((string) $mediaUser->status)) ?> fs-6"><?= e($mediaUser->status) ?></span>
            <span class="badge <?= e($mb['class']) ?> fs-6" title="<?= e($mb['hint']) ?>"><?= e($mb['label']) ?></span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnSyncMembership" title="Reconsulta la lista del servidor para este usuario">
                <i class="bi bi-arrow-repeat me-1"></i>Forzar sincronización
            </button>
        </div>
    </div>
    <?php if (!empty($mediaUser->membership_synced_at)): ?>
    <p class="small text-muted mt-2 mb-0">Última comprobación de biblioteca: <?= e($mediaUser->membership_synced_at) ?></p>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Editar datos del usuario</strong></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">Nombre de usuario</label>
                        <input type="text" id="editUsername" class="form-control form-control-sm" value="<?= e($mediaUser->username) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Nombre visible</label>
                        <input type="text" id="editDisplayName" class="form-control form-control-sm" value="<?= e($mediaUser->display_name ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Email</label>
                        <input type="email" id="editEmail" class="form-control form-control-sm" value="<?= e($mediaUser->email ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Streams</label>
                        <input type="number" min="1" max="50" id="editMaxStreams" class="form-control form-control-sm"
                               value="<?= $mediaUser->max_streams !== null && $mediaUser->max_streams !== '' ? (int) $mediaUser->max_streams : '' ?>"
                               placeholder="Def. <?= (int) ($defaultMaxStreams ?? 2) ?>"
                               title="Vacío = límite por defecto del tenant">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Dispositivos</label>
                        <input type="number" min="1" id="editMaxDevices" class="form-control form-control-sm" value="<?= (int) $mediaUser->max_devices ?>">
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-3" id="btnSaveProfile"><i class="bi bi-save me-1"></i>Guardar datos</button>
            </div>
        </div>

        <?php if (($serverType ?? null) === 'jellyfin'): ?>
        <div class="card border-0 shadow-sm mb-4" id="jellyfinCredentialsCard">
            <div class="card-header bg-white"><strong><i class="bi bi-key me-1"></i>Credenciales Jellyfin</strong></div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small mb-1">Usuario</label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="jellyfinUsername" class="form-control" value="<?= e($mediaUser->username) ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="btnCopyJellyfinUser" title="Copiar usuario"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1">Contraseña</label>
                    <div class="input-group input-group-sm">
                        <input type="password" id="jellyfinPassword" class="form-control" value="<?= e($jellyfinPassword ?? '') ?>" readonly placeholder="<?= ($jellyfinPassword ?? '') === '' ? 'Sin contraseña guardada' : '' ?>">
                        <button type="button" class="btn btn-outline-secondary" id="btnRevealJellyfinPassword" title="Mostrar/ocultar" <?= ($jellyfinPassword ?? '') === '' ? 'disabled' : '' ?>><i class="bi bi-eye"></i></button>
                        <button type="button" class="btn btn-outline-secondary" id="btnCopyJellyfinPassword" title="Copiar contraseña" <?= ($jellyfinPassword ?? '') === '' ? 'disabled' : '' ?>><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btnRegenJellyfinPassword">
                        <i class="bi bi-arrow-repeat me-1"></i>Regenerar contraseña
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btnSendJellyfinTelegram" <?= $mediaUser->telegram_chat_id ? '' : 'disabled title="Sin Telegram Chat ID"' ?>>
                        <i class="bi bi-telegram me-1"></i>Enviar por Telegram
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnSendJellyfinWhatsapp">
                        <i class="bi bi-whatsapp me-1"></i>WhatsApp
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCopyJellyfinCredentials" <?= ($credentialsText ?? '') === '' ? 'disabled' : '' ?>>
                        <i class="bi bi-clipboard-check me-1"></i>Copiar mensaje
                    </button>
                </div>
                <label class="form-label small mb-1">Texto para enviar al cliente</label>
                <textarea id="jellyfinCredentialsText" class="form-control form-control-sm" rows="5" readonly><?= e($credentialsText ?? '') ?></textarea>
                <p class="small text-muted mb-0 mt-2">La contraseña se guarda cifrada con APP_KEY. Tras regenerar, se actualiza también en Jellyfin.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Control del usuario</strong></div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small"><i class="bi bi-telegram me-1"></i>Telegram Chat ID</label>
                        <input type="text" id="telegramChatId" class="form-control form-control-sm" value="<?= e($mediaUser->telegram_chat_id ?? '') ?>" placeholder="Ej. 2023182976">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><i class="bi bi-whatsapp me-1"></i>WhatsApp</label>
                        <input type="text" id="whatsappPhone" class="form-control form-control-sm" value="<?= e($mediaUser->metaGet('whatsapp_phone') ?? '') ?>" placeholder="Ej. 34612345678 (con código de país, sin +)">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Fecha expiración</label>
                    <input type="date" id="expiresAt" class="form-control form-control-sm" value="<?= e($mediaUser->expires_at ? substr((string) $mediaUser->expires_at, 0, 10) : '') ?>">
                </div>
                <div class="mb-3 d-flex flex-wrap gap-2">
                    <span class="small text-muted w-100">Sumar días:</span>
                    <?php foreach ([7, 15, 30, 90, 365] as $days): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary btn-add-days" data-days="<?= $days ?>">+<?= $days ?>d</button>
                    <?php endforeach; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Notas privadas <span class="text-muted">(identificación, incidencias, etc.)</span></label>
                    <textarea id="userNotes" class="form-control form-control-sm" rows="4" placeholder="Ej: cliente habitual, pagó por Bizum el día 3, tuvo problema de buffering…"><?= e($mediaUser->notes ?? '') ?></textarea>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-success btn-sm" id="btnActivate" <?= $mediaUser->status === 'active' ? 'disabled' : '' ?>><i class="bi bi-play me-1"></i>Activar</button>
                    <button type="button" class="btn btn-warning btn-sm" id="btnSuspend" <?= $mediaUser->status === 'suspended' ? 'disabled' : '' ?>><i class="bi bi-pause me-1"></i>Suspender</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnSyncMembershipControl" title="Comprobar si sigue en la biblioteca del servidor"><i class="bi bi-arrow-repeat me-1"></i>Comprobar biblioteca</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="btnRemoveServer"><i class="bi bi-person-x me-1"></i>Quitar del servidor</button>
                </div>
                <p class="small text-muted mt-2 mb-0"><?= e($mb['hint']) ?></p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" id="stripe">
            <div class="card-header bg-white"><strong><i class="bi bi-credit-card me-1"></i>Cobro con Stripe</strong></div>
            <div class="card-body">
                <p class="small text-muted mb-2">Genera un enlace de pago para que el cliente renueve. El cliente solo verá el concepto configurado en Ajustes (ej. "Digital services") y el precio; en cuanto Stripe confirme el cobro, se le sumarán los días automáticamente y se reactivará su acceso.</p>

                <label class="form-label small">Duración</label>
                <select id="stripePreset" class="form-select form-select-sm mb-2">
                    <option value="">Personalizado…</option>
                    <?php foreach ($renewalPresets as $i => $p): ?>
                    <option value="<?= (int) $i ?>" data-days="<?= (int) $p['days'] ?>" data-price="<?= e($p['price']) ?>"><?= e($p['label']) ?> · <?= number_format((float) $p['price'], 2) ?> €</option>
                    <?php endforeach; ?>
                </select>

                <div class="row g-2 mb-2">
                    <div class="col-5">
                        <label class="form-label small">Importe</label>
                        <div class="input-group input-group-sm">
                            <input type="number" step="0.01" min="0.5" id="stripeAmount" class="form-control" value="9.99">
                            <span class="input-group-text">EUR</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">Días a sumar</label>
                        <input type="number" min="1" id="stripeDays" class="form-control form-control-sm" value="30">
                    </div>
                    <div class="col-3 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-primary w-100" id="btnStripeCheckout" title="Generar enlace"><i class="bi bi-link-45deg"></i></button>
                    </div>
                </div>
                <div id="stripeLinkBox" class="d-none">
                    <label class="form-label small">Enlace de pago</label>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" id="stripeLink" class="form-control" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="btnCopyStripeLink" title="Copiar"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success flex-fill" id="btnSendStripeWhatsapp">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info flex-fill" id="btnSendStripeLink" <?= $mediaUser->telegram_chat_id ? '' : 'disabled title="El usuario no tiene Telegram configurado"' ?>>
                            <i class="bi bi-telegram me-1"></i>Telegram
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Enviar mensaje</strong></div>
            <div class="card-body">
                <input type="text" id="msgTitle" class="form-control form-control-sm mb-2" value="Aviso" placeholder="Título">
                <textarea id="msgBody" class="form-control form-control-sm mb-2" rows="5" placeholder="Mensaje…"></textarea>
                <p class="small text-muted mb-2">Variables: {username}, {email}, {display_name}, {end_date}</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-info btn-sm flex-fill" id="btnSendMsg"><i class="bi bi-telegram me-1"></i>Telegram</button>
                    <button type="button" class="btn btn-outline-success btn-sm flex-fill" id="btnSendMsgWhatsapp"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if (!empty($nowPlaying)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-play-circle-fill text-success me-1"></i>Reproduciendo ahora</strong>
                <span class="badge bg-success"><?= count($nowPlaying) ?> activa(s)</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($nowPlaying as $session): ?>
                    <?php include base_path('resources/views/activity/_session_card.php'); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>Historial de actividad</strong>
                <a href="/media-users/activity" class="small">Ver global</a>
            </div>
            <div class="list-group list-group-flush" style="max-height: 420px; overflow-y: auto;">
                <?php if (empty($timeline)): ?>
                <div class="list-group-item text-muted small">Sin movimientos registrados</div>
                <?php else: ?>
                <?php foreach ($timeline as $event): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <span><i class="bi bi-<?= e($event['icon'] ?? 'clock') ?> me-1"></i><?= e($event['label']) ?></span>
                        <span class="small text-muted text-nowrap"><?= e($event['at']) ?></span>
                    </div>
                    <?php if (!empty($event['detail'])): ?>
                    <div class="small text-muted mt-1"><?= e($event['detail']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Últimos mensajes enviados</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Fecha</th><th>Tipo</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="3" class="text-muted text-center py-3">Sin mensajes</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td class="small"><?= e($msg['sent_at']) ?></td>
                        <td class="small"><?= e($msg['message_type']) ?></td>
                        <td><span class="badge bg-<?= $msg['status'] === 'sent' ? 'success' : 'danger' ?>"><?= e($msg['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = '<script>window.MEDIA_USER_UUID = ' . json_encode($mediaUser->uuid) . ';';
$scripts .= 'window.MEDIA_USER_WHATSAPP = ' . json_encode($mediaUser->metaGet('whatsapp_phone')) . ';';
$scripts .= 'window.MEDIA_USER_SERVER_TYPE = ' . json_encode($serverType ?? null) . ';</script>';
$scripts .= '<script src="' . e(asset('js/media-user-show.js')) . '"></script>';
include base_path('resources/views/layouts/app.php');
