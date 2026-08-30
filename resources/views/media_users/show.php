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
            <span id="membershipBadge" class="badge <?= e($mb['class']) ?> fs-5 px-3 py-2" title="<?= e($mb['hint']) ?>"><?= e($mb['label']) ?></span>
            <button type="button" class="btn btn-primary" id="btnSyncMembership" title="Reconsulta la lista del servidor para este usuario">
                <i class="bi bi-arrow-repeat me-1"></i>Comprobar biblioteca
            </button>
        </div>
    </div>
    <div id="membershipResult" class="alert mt-3 mb-0 <?= (int) ($mediaUser->on_server ?? -1) === 1 ? 'alert-success' : ((int) ($mediaUser->on_server ?? -1) === 0 ? 'alert-danger' : 'alert-secondary') ?>">
        <strong id="membershipResultLabel"><?= e($mb['label']) ?>.</strong>
        <span id="membershipResultHint"><?= e($mb['hint']) ?></span>
        <?php if (!empty($mediaUser->membership_synced_at)): ?>
        <span class="d-block small mt-1 text-muted">Última comprobación: <?= e($mediaUser->membership_synced_at) ?></span>
        <?php endif; ?>
    </div>
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
                        <label class="form-label small">En casa</label>
                        <input type="number" min="1" max="50" id="editMaxHomeStreams" class="form-control form-control-sm"
                               value="<?= $mediaUser->max_home_streams !== null && $mediaUser->max_home_streams !== '' ? (int) $mediaUser->max_home_streams : ($mediaUser->max_streams !== null && $mediaUser->max_streams !== '' ? (int) $mediaUser->max_streams : '') ?>"
                               placeholder="Def. <?= (int) ($defaultMaxStreams ?? 2) ?>"
                               title="Vacío = límite en casa del tenant (<?= (int) ($defaultMaxStreams ?? 2) ?>)">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Fuera</label>
                        <input type="number" min="0" max="20" id="editMaxAwayStreams" class="form-control form-control-sm"
                               value="<?= $mediaUser->max_away_streams !== null && $mediaUser->max_away_streams !== '' ? (int) $mediaUser->max_away_streams : '' ?>"
                               placeholder="Def. <?= (int) ($defaultMaxAwayStreams ?? 0) ?>"
                               title="Vacío = fuera del tenant (<?= (int) ($defaultMaxAwayStreams ?? 0) ?>). 0 = no se usa fuera de casa">
                    </div>
                    <input type="hidden" id="editMaxStreams" value="<?= $mediaUser->max_streams !== null && $mediaUser->max_streams !== '' ? (int) $mediaUser->max_streams : '' ?>">
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
                        <input type="text" id="telegramChatId" class="form-control form-control-sm" value="<?= e((string) ($mediaUser->telegram_chat_id ?? '')) ?>" placeholder="Ej. 2023182976">
                        <div class="form-text">El cliente también puede vincularlo solo en el portal → Mi ficha (código de un uso).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><i class="bi bi-whatsapp me-1"></i>WhatsApp</label>
                        <input type="text" id="whatsappPhone" class="form-control form-control-sm" value="<?= e($mediaUser->metaGet('whatsapp_phone') ?? '') ?>" placeholder="Ej. 34612345678 (con código de país, sin +)">
                        <div class="form-text">Avisos automáticos a clientes: WhatsApp Cloud API en Configuración. CallMeBot de ajustes es solo admin.</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Fecha expiración</label>
                    <input type="date" id="expiresAt" class="form-control form-control-sm expires-input media-users-expires-input"
                           data-db-status="<?= e((string) $mediaUser->status) ?>"
                           value="<?= e(expires_date_input($mediaUser->expires_at)) ?>"
                           title="Vacío = sin caducidad">
                    <div class="form-text">Deja vacío para acceso sin caducidad (indefinido). No se enviarán avisos de vencimiento.</div>
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
                    <button type="button" class="btn btn-outline-info btn-sm" id="btnDiscoverIdentity" title="Buscar email/usuario en servidor, clientes o registros previos">
                        <i class="bi bi-search me-1"></i>Buscar email / usuario
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnSyncMembershipControl" title="Comprobar si sigue en la biblioteca del servidor"><i class="bi bi-arrow-repeat me-1"></i>Comprobar biblioteca</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="btnRemoveServer"><i class="bi bi-person-x me-1"></i>Quitar del servidor</button>
                    <form method="POST" action="/media-users/<?= e($mediaUser->uuid) ?>" class="d-inline"
                          onsubmit="return confirm('¿Eliminar este usuario del panel? No borra la cuenta en Plex/Jellyfin.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Soft-delete en el panel">
                            <i class="bi bi-trash me-1"></i>Eliminar del panel
                        </button>
                    </form>
                </div>
                <p class="small text-muted mt-2 mb-0"><?= e($mb['hint']) ?></p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" id="portal-link">
            <div class="card-header bg-white"><strong><i class="bi bi-key me-1"></i>Enlace al portal (sin contraseña)</strong></div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    El cliente abre el enlace y entra directo a su cuenta: ver ficha, comprar, pedir peli…
                    Quien tenga el enlace entra como este usuario. Caduca; generar uno nuevo cancela el anterior.
                    La URL completa solo se muestra al crearla (no se puede recuperar).
                </p>
                <?php
                $pl = is_array($portalLink ?? null) ? $portalLink : [];
                $plActive = !empty($pl['has_active']);
                ?>
                <p class="small mb-2 <?= $plActive ? 'text-success' : 'text-muted' ?>" id="portalLinkStatus">
                    <?php if ($plActive): ?>
                    Hay un enlace activo<?= !empty($pl['expires_at']) ? ' hasta ' . e(substr((string) $pl['expires_at'], 0, 10)) : '' ?><?= ($pl['purpose'] ?? '') === 'pay' ? ' · abre en Comprar' : '' ?>.
                    <?php else: ?>
                    No hay enlace activo.
                    <?php endif; ?>
                </p>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small" for="portalLinkPurpose">Al abrir</label>
                        <select id="portalLinkPurpose" class="form-select form-select-sm">
                            <option value="home">Inicio del portal</option>
                            <option value="pay">Directo a Comprar</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="portalLinkDays">Válido</label>
                        <select id="portalLinkDays" class="form-select form-select-sm">
                            <option value="7">7 días</option>
                            <option value="30" selected>30 días</option>
                            <option value="90">90 días</option>
                            <option value="365">1 año</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-primary" id="btnPortalLinkCreate">
                        <i class="bi bi-link-45deg me-1"></i>Generar enlace
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnPortalLinkRevoke">
                        Cancelar enlace
                    </button>
                </div>
                <div id="portalLinkBox" class="d-none">
                    <label class="form-label small">Cópialo ahora</label>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" id="portalLinkUrl" class="form-control" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="btnCopyPortalLink" title="Copiar"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success flex-fill" id="btnSendPortalWhatsapp">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info flex-fill" id="btnSendPortalTelegram" <?= $mediaUser->telegram_chat_id ? '' : 'disabled title="Sin Telegram"' ?>>
                            <i class="bi bi-telegram me-1"></i>Telegram
                        </button>
                    </div>
                </div>
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
                <div class="row g-2 g-xl-3">
                    <?php foreach ($nowPlaying as $session): ?>
                    <?php
                    // getSessionsForUser no pasa por ConcurrentStreamLimitService;
                    // en la ficha ya conocemos el uuid del usuario.
                    if (empty($session['media_user_uuid']) && !empty($mediaUser->uuid)) {
                        $session['media_user_uuid'] = (string) $mediaUser->uuid;
                    }
                    include base_path('resources/views/activity/_session_card.php');
                    ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $endpoints = is_array($endpoints ?? null) ? $endpoints : [];
        $kindLabel = static function (string $kind): array {
            return match ($kind) {
                'home' => ['Hogar', 'success'],
                'away' => ['Fuera', 'danger'],
                default => ['Por ver', 'secondary'],
            };
        };
        ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong>IPs y dispositivos</strong>
                <div class="small text-muted fw-normal">Se guarda al reproducir. Marca hogar o fuera para el corte.</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>IP</th>
                            <th>Dispositivo</th>
                            <th>Red</th>
                            <th>Visto</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($endpoints === []): ?>
                    <tr><td colspan="5" class="text-muted text-center py-3">Aún no hay reproducciones registradas</td></tr>
                    <?php else: ?>
                    <?php foreach ($endpoints as $ep): ?>
                    <?php $kl = $kindLabel((string) ($ep['kind'] ?? 'unknown')); ?>
                    <tr>
                        <td class="small">
                            <code><?= e((string) (($ep['ip'] ?? '') !== '' ? $ep['ip'] : '—')) ?></code>
                            <?php if (!empty($ep['lan_ip']) && (string) $ep['lan_ip'] !== (string) ($ep['ip'] ?? '')): ?>
                            <div class="text-muted">LAN <?= e((string) $ep['lan_ip']) ?></div>
                            <?php endif; ?>
                            <div><span class="badge bg-<?= e($kl[1]) ?>"><?= e($kl[0]) ?></span></div>
                        </td>
                        <td class="small">
                            <?= e((string) (($ep['device_name'] ?? '') !== '' ? $ep['device_name'] : '—')) ?>
                            <div class="text-muted"><?= e(trim((string) (($ep['product'] ?? '') . ' ' . ($ep['platform'] ?? '')))) ?></div>
                        </td>
                        <td class="small"><?= e((string) ($ep['location'] ?? '—')) ?></td>
                        <td class="small text-nowrap">
                            <?= (int) ($ep['play_count'] ?? 0) ?>×
                            <div class="text-muted"><?= e((string) ($ep['last_seen_at'] ?? '')) ?></div>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-outline-success btn-sm btn-ep-kind" data-ep-id="<?= (int) $ep['id'] ?>" data-kind="home">Hogar</button>
                            <button type="button" class="btn btn-outline-danger btn-sm btn-ep-kind" data-ep-id="<?= (int) $ep['id'] ?>" data-kind="away">Fuera</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        $playbackHistory = is_array($playbackHistory ?? null) ? $playbackHistory : [];
        $playbackHistoryTotal = (int) ($playbackHistoryTotal ?? count($playbackHistory));
        $formatPlaybackDuration = static function (?int $seconds, ?string $startedAt, ?string $endedAt): string {
            if ($seconds !== null && $seconds > 0) {
                $h = intdiv($seconds, 3600);
                $m = intdiv($seconds % 3600, 60);
                if ($h > 0) {
                    return sprintf('%dh %02dm', $h, $m);
                }

                return $m > 0 ? $m . ' min' : $seconds . ' s';
            }
            if ($endedAt === null || $endedAt === '') {
                return 'En curso';
            }

            return '—';
        };
        ?>
        <div class="card border-0 shadow-sm mb-4" id="playback-history-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <strong>Historial de reproducción</strong>
                    <div class="small text-muted fw-normal">Se registra al ver contenido en directo o en sincronización del servidor.</div>
                </div>
                <?php if ($playbackHistoryTotal > 0): ?>
                <span class="badge bg-secondary" id="playback-history-count"><?= $playbackHistoryTotal ?></span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Título</th>
                            <th>Dispositivo</th>
                            <th>Inicio</th>
                            <th>Duración</th>
                        </tr>
                    </thead>
                    <tbody id="playback-history-body">
                    <?php if ($playbackHistory === []): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">Aún no hay reproducciones registradas</td></tr>
                    <?php else: ?>
                    <?php foreach ($playbackHistory as $row): ?>
                    <tr>
                        <td class="small">
                            <div class="fw-semibold"><?= e((string) ($row['title'] ?? '—')) ?></div>
                            <?php if (!empty($row['subtitle'])): ?>
                            <div class="text-muted"><?= e((string) $row['subtitle']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($row['server_name'])): ?>
                            <div class="text-muted"><?= e((string) $row['server_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= e((string) (($row['player'] ?? '') !== '' ? $row['player'] : '—')) ?>
                            <?php if (!empty($row['device'])): ?>
                            <div class="text-muted"><?= e((string) $row['device']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($row['ip_address'])): ?>
                            <div class="text-muted"><code><?= e((string) $row['ip_address']) ?></code></div>
                            <?php endif; ?>
                        </td>
                        <td class="small text-nowrap"><?= e((string) ($row['started_at'] ?? '—')) ?></td>
                        <td class="small text-nowrap"><?= e($formatPlaybackDuration(
                            isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
                            isset($row['started_at']) ? (string) $row['started_at'] : null,
                            isset($row['ended_at']) ? (string) $row['ended_at'] : null,
                        )) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($playbackHistoryTotal > count($playbackHistory)): ?>
            <div class="card-footer bg-white text-center">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="playback-history-more"
                        data-page="1" data-limit="40">
                    Cargar más
                </button>
            </div>
            <?php endif; ?>
        </div>

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
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Historial de avisos</strong>
                <a href="/media-users/<?= e($mediaUser->uuid) ?>/messages" class="small">Ver todos</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Canal</th>
                            <th>Tipo</th>
                            <th>Aviso</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="6" class="text-muted text-center py-3">Sin avisos registrados</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <?php
                        $snippet = trim((string) ($msg['title'] ?? ''));
                        if ($snippet === '') {
                            $snippet = mb_substr(trim((string) ($msg['body'] ?? '')), 0, 80);
                        } else {
                            $bodyBit = mb_substr(trim((string) ($msg['body'] ?? '')), 0, 60);
                            if ($bodyBit !== '') {
                                $snippet .= ' — ' . $bodyBit;
                            }
                        }
                        $failed = ($msg['status'] ?? '') === 'failed';
                    ?>
                    <tr class="<?= $failed ? 'table-danger' : '' ?>">
                        <td class="small text-nowrap"><?= e($msg['sent_at'] ?? '') ?></td>
                        <td class="small"><?= e($msg['channel'] ?? '—') ?></td>
                        <td class="small"><span class="badge bg-secondary"><?= e($msg['message_type'] ?? '') ?></span></td>
                        <td class="small text-truncate" style="max-width: 14rem;" title="<?= e((string) ($msg['body'] ?? '')) ?>"><?= e($snippet) ?></td>
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
                                <i class="bi bi-arrow-repeat"></i>
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
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = '<script>window.MEDIA_USER_UUID = ' . json_encode($mediaUser->uuid) . ';';
$scripts .= 'window.MEDIA_USER_WHATSAPP = ' . json_encode($mediaUser->metaGet('whatsapp_phone')) . ';';
$scripts .= 'window.MEDIA_USER_SERVER_TYPE = ' . json_encode($serverType ?? null) . ';</script>';
$scripts .= '<script src="' . e(asset('js/media-user-show.js')) . '"></script>';
include base_path('resources/views/layouts/app.php');
