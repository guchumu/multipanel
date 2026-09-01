<?php
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

$displayName = (string) ($mediaUser->display_name ?? $mediaUser->username);
$initial = mb_strtoupper(mb_substr(trim($displayName), 0, 1));
$endpoints = is_array($endpoints ?? null) ? $endpoints : [];
$playbackHistory = is_array($playbackHistory ?? null) ? $playbackHistory : [];
$playbackHistoryTotal = (int) ($playbackHistoryTotal ?? count($playbackHistory));
$nowPlaying = is_array($nowPlaying ?? null) ? $nowPlaying : [];
$timeline = is_array($timeline ?? null) ? $timeline : [];
$messages = is_array($messages ?? null) ? $messages : [];
$expiresLabel = expires_date_input($mediaUser->expires_at);
$expiresDisplay = $expiresLabel !== '' ? date('d/m/Y', strtotime($expiresLabel)) : 'Sin caducidad';
$kindLabel = static function (string $kind): array {
    return match ($kind) {
        'home' => ['Hogar', 'success'],
        'away' => ['Fuera', 'danger'],
        default => ['Por ver', 'secondary'],
    };
};
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
$statusIcon = match ((string) $mediaUser->status) {
    'active' => 'person-check',
    'suspended' => 'pause-circle',
    'pending' => 'hourglass-split',
    default => 'person',
};
$statusIconBg = match ((string) $mediaUser->status) {
    'active' => 'success',
    'suspended' => 'warning',
    'pending' => 'secondary',
    default => 'light',
};
$statusLabel = match ((string) $mediaUser->status) {
    'active' => 'Activo',
    'suspended' => 'Suspendido',
    'pending' => 'Pendiente',
    default => ucfirst((string) $mediaUser->status),
};
$daysLeft = null;
if ($expiresLabel !== '') {
    $expTs = strtotime($expiresLabel);
    if ($expTs !== false) {
        $daysLeft = (int) floor(($expTs - strtotime('today')) / 86400);
    }
}
$accountPanelTone = match (true) {
    (string) $mediaUser->status === 'suspended' => 'suspended',
    (string) $mediaUser->status === 'pending' => 'pending',
    $daysLeft !== null && $daysLeft < 0 => 'expired',
    $daysLeft !== null && $daysLeft <= 14 => 'warning',
    default => 'active',
};

ob_start();
?>
<div class="media-user-page">
    <div class="mb-3">
        <a href="/media-users" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Volver a usuarios
        </a>
    </div>

    <div class="media-user-identity mb-3">
        <div class="d-flex align-items-center gap-3">
            <div class="media-user-avatar media-user-avatar--sm" aria-hidden="true"><?= e($initial) ?></div>
            <div class="flex-grow-1 min-width-0">
                <h5 class="mb-0 fw-bold text-truncate"><?= e($displayName) ?></h5>
                <p class="text-muted small mb-0 text-truncate">
                    <span class="me-2">@<?= e($mediaUser->username) ?></span>
                    <span class="me-2">· <?= e($mediaUser->email ?? 'Sin email') ?></span>
                    <span>· <?= e($mediaUser->server_name ?? 'Sin servidor') ?></span>
                </p>
            </div>
            <span class="badge bg-light text-dark border d-none d-md-inline">#<?= (int) $mediaUser->id ?></span>
        </div>
    </div>

    <section id="mu-account-panel" class="mu-account-panel mu-account-panel--<?= e($accountPanelTone) ?> mb-4" aria-label="Estado de la cuenta">
        <div class="mu-account-panel__header">
            <span class="mu-account-panel__kicker"><i class="bi bi-shield-check me-1"></i>Estado de la cuenta</span>
        </div>
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-4">
                <div class="mu-status-block">
                    <div class="mu-status-icon mu-status-icon--<?= e($accountPanelTone) ?>">
                        <i class="bi bi-<?= e($statusIcon) ?>"></i>
                    </div>
                    <div class="mu-status-label"><?= e($statusLabel) ?></div>
                    <div class="mu-status-meta">
                        <span id="membershipBadge" class="badge <?= e($mb['class']) ?>" title="<?= e($mb['hint']) ?>"><?= e($mb['label']) ?></span>
                    </div>
                    <div class="mu-status-actions mt-3">
                        <button type="button" class="btn btn-success mu-action-btn" id="btnActivate" <?= $mediaUser->status === 'active' ? 'disabled' : '' ?>>
                            <i class="bi bi-play-fill me-1"></i>Reactivar
                        </button>
                        <button type="button" class="btn btn-warning mu-action-btn" id="btnSuspend" <?= $mediaUser->status === 'suspended' ? 'disabled' : '' ?>>
                            <i class="bi bi-pause-fill me-1"></i>Pausar
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="mu-expiry-block h-100">
                    <div class="mu-expiry-head">
                        <?php if ($expiresLabel === ''): ?>
                        <div class="mu-expiry-big">∞</div>
                        <div class="mu-expiry-caption">Sin fecha de caducidad</div>
                        <?php elseif ($daysLeft !== null && $daysLeft < 0): ?>
                        <div class="mu-expiry-big text-danger"><?= abs($daysLeft) ?></div>
                        <div class="mu-expiry-caption">días de retraso · venció el <?= e($expiresDisplay) ?></div>
                        <?php elseif ($daysLeft !== null): ?>
                        <div class="mu-expiry-big"><?= $daysLeft ?></div>
                        <div class="mu-expiry-caption">días restantes · hasta el <?= e($expiresDisplay) ?></div>
                        <?php else: ?>
                        <div class="mu-expiry-big"><?= e($expiresDisplay) ?></div>
                        <div class="mu-expiry-caption">fecha de caducidad</div>
                        <?php endif; ?>
                    </div>
                    <label class="form-label small fw-semibold mt-3 mb-1" for="expiresAt">Cambiar fecha</label>
                    <input type="date" id="expiresAt" class="form-control expires-input media-users-expires-input mu-expiry-input"
                           data-db-status="<?= e((string) $mediaUser->status) ?>"
                           value="<?= e(expires_date_input($mediaUser->expires_at)) ?>"
                           title="Vacío = sin caducidad">
                    <div class="form-text mb-2">Vacío = acceso indefinido</div>
                    <div class="mu-day-chips">
                        <?php foreach ([7, 15, 30, 90, 365] as $days): ?>
                        <button type="button" class="mu-day-chip btn-add-days" data-days="<?= $days ?>">+<?= $days ?>d</button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-3">
                <div class="mu-library-block h-100">
                    <div class="mu-library-title"><i class="bi bi-collection me-1"></i>Biblioteca</div>
                    <div id="membershipResult" class="mu-membership-box mu-membership-box--<?= (int) ($mediaUser->on_server ?? -1) === 1 ? 'ok' : ((int) ($mediaUser->on_server ?? -1) === 0 ? 'bad' : 'unknown') ?>">
                        <i class="bi bi-<?= (int) ($mediaUser->on_server ?? -1) === 1 ? 'check-circle-fill' : ((int) ($mediaUser->on_server ?? -1) === 0 ? 'x-circle-fill' : 'question-circle') ?>"></i>
                        <div>
                            <strong id="membershipResultLabel"><?= e($mb['label']) ?></strong>
                            <span id="membershipResultHint" class="d-block small"><?= e($mb['hint']) ?></span>
                            <?php if (!empty($mediaUser->membership_synced_at)): ?>
                            <span class="d-block small mt-1 opacity-75">Última comprobación: <?= e($mediaUser->membership_synced_at) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary w-100 mt-3" id="btnSyncMembership">
                        <i class="bi bi-arrow-repeat me-1"></i>Comprobar biblioteca
                    </button>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-2 btn-sm" id="btnSyncMembershipControl">
                        <i class="bi bi-cloud-check me-1"></i>Sincronizar estado
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-2 g-md-3 mb-4">
        <div class="col-4">
            <div class="card media-user-stat media-user-stat--compact">
                <div class="card-body d-flex align-items-center gap-2 py-2 px-3">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-collection-play"></i>
                    </div>
                    <div>
                        <div class="stat-label">Reproducciones</div>
                        <div class="stat-value" id="playback-history-count-stat"><?= $playbackHistoryTotal ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card media-user-stat media-user-stat--compact">
                <div class="card-body d-flex align-items-center gap-2 py-2 px-3">
                    <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                        <i class="bi bi-phone"></i>
                    </div>
                    <div>
                        <div class="stat-label">Dispositivos</div>
                        <div class="stat-value"><?= count($endpoints) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card media-user-stat media-user-stat--compact">
                <div class="card-body d-flex align-items-center gap-2 py-2 px-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-play-circle"></i>
                    </div>
                    <div>
                        <div class="stat-label">En directo</div>
                        <div class="stat-value"><?= count($nowPlaying) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs media-user-tabs" id="mediaUserTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-resumen-btn" data-bs-toggle="tab" data-bs-target="#tab-resumen" type="button" role="tab">
                <i class="bi bi-grid-1x2"></i>Resumen
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-perfil-btn" data-bs-toggle="tab" data-bs-target="#tab-perfil" type="button" role="tab">
                <i class="bi bi-person-gear"></i>Perfil
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-cuenta-btn" data-bs-toggle="tab" data-bs-target="#tab-cuenta" type="button" role="tab">
                <i class="bi bi-three-dots"></i>Más opciones
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-actividad-btn" data-bs-toggle="tab" data-bs-target="#tab-actividad" type="button" role="tab">
                <i class="bi bi-activity"></i>Actividad
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-comunicacion-btn" data-bs-toggle="tab" data-bs-target="#tab-comunicacion" type="button" role="tab">
                <i class="bi bi-chat-dots"></i>Comunicación
            </button>
        </li>
    </ul>

    <div class="tab-content media-user-tab-panel" id="mediaUserTabContent">
        <div class="tab-pane fade show active" id="tab-resumen" role="tabpanel">
            <?php if ($nowPlaying !== []): ?>
            <div class="mb-4">
                <div class="section-title"><i class="bi bi-play-circle-fill"></i>Reproduciendo ahora <span class="badge bg-success ms-auto"><?= count($nowPlaying) ?> activa(s)</span></div>
                <div class="row g-2 g-xl-3">
                    <?php foreach ($nowPlaying as $session): ?>
                    <?php
                    if (empty($session['media_user_uuid']) && !empty($mediaUser->uuid)) {
                        $session['media_user_uuid'] = (string) $mediaUser->uuid;
                    }
                    include base_path('resources/views/activity/_session_card.php');
                    ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="section-title"><i class="bi bi-clock-history"></i>Actividad reciente</div>
                    <div class="list-group list-group-flush border rounded">
                        <?php if ($timeline === []): ?>
                        <div class="list-group-item text-muted small py-3">Sin movimientos registrados</div>
                        <?php else: ?>
                        <?php foreach (array_slice($timeline, 0, 6) as $event): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="small"><i class="bi bi-<?= e($event['icon'] ?? 'clock') ?> me-1 text-primary"></i><?= e($event['label']) ?></span>
                                <span class="small text-muted text-nowrap"><?= e($event['at']) ?></span>
                            </div>
                            <?php if (!empty($event['detail'])): ?>
                            <div class="small text-muted mt-1"><?= e($event['detail']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($timeline) > 6): ?>
                        <div class="list-group-item text-center py-2">
                            <button type="button" class="btn btn-link btn-sm" data-mu-tab="tab-actividad">Ver todo el historial</button>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="section-title"><i class="bi bi-envelope"></i>Últimos avisos</div>
                    <div class="list-group list-group-flush border rounded">
                        <?php if ($messages === []): ?>
                        <div class="list-group-item text-muted small py-3">Sin avisos registrados</div>
                        <?php else: ?>
                        <?php foreach (array_slice($messages, 0, 5) as $msg): ?>
                        <?php
                            $snippet = trim((string) ($msg['title'] ?? ''));
                            if ($snippet === '') {
                                $snippet = mb_substr(trim((string) ($msg['body'] ?? '')), 0, 80);
                            }
                            $failed = ($msg['status'] ?? '') === 'failed';
                        ?>
                        <div class="list-group-item <?= $failed ? 'list-group-item-danger' : '' ?>">
                            <div class="d-flex justify-content-between gap-2 align-items-start">
                                <div class="small">
                                    <span class="badge bg-secondary me-1"><?= e($msg['channel'] ?? '—') ?></span>
                                    <?= e($snippet) ?>
                                </div>
                                <span class="badge bg-<?= $failed ? 'danger' : 'success' ?>"><?= $failed ? 'fallido' : 'ok' ?></span>
                            </div>
                            <div class="small text-muted mt-1"><?= e($msg['sent_at'] ?? '') ?></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="list-group-item text-center py-2">
                            <button type="button" class="btn btn-link btn-sm" data-mu-tab="tab-comunicacion">Ver todos los avisos</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top">
                <div class="section-title mb-3"><i class="bi bi-lightning"></i>Accesos rápidos</div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" data-mu-scroll="mu-account-panel"><i class="bi bi-shield-check me-1"></i>Estado de cuenta</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-mu-tab="tab-perfil"><i class="bi bi-pencil me-1"></i>Editar perfil</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-mu-tab="tab-comunicacion" data-mu-subtab="portal-link"><i class="bi bi-link-45deg me-1"></i>Enlace portal</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-mu-tab="tab-comunicacion" data-mu-subtab="stripe"><i class="bi bi-credit-card me-1"></i>Cobro Stripe</button>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-perfil" role="tabpanel">
            <div class="section-title"><i class="bi bi-person"></i>Editar datos del usuario</div>
            <div class="row g-2 mb-4">
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
            <button type="button" class="btn btn-sm btn-primary" id="btnSaveProfile"><i class="bi bi-save me-1"></i>Guardar datos</button>

            <?php if (($serverType ?? null) === 'jellyfin'): ?>
            <hr class="my-4">
            <div id="jellyfinCredentialsCard">
                <div class="section-title"><i class="bi bi-key"></i>Credenciales Jellyfin</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Usuario</label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="jellyfinUsername" class="form-control" value="<?= e($mediaUser->username) ?>" readonly>
                            <button type="button" class="btn btn-outline-secondary" id="btnCopyJellyfinUser" title="Copiar usuario"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Contraseña</label>
                        <div class="input-group input-group-sm">
                            <input type="password" id="jellyfinPassword" class="form-control" value="<?= e($jellyfinPassword ?? '') ?>" readonly placeholder="<?= ($jellyfinPassword ?? '') === '' ? 'Sin contraseña guardada' : '' ?>">
                            <button type="button" class="btn btn-outline-secondary" id="btnRevealJellyfinPassword" title="Mostrar/ocultar" <?= ($jellyfinPassword ?? '') === '' ? 'disabled' : '' ?>><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-outline-secondary" id="btnCopyJellyfinPassword" title="Copiar contraseña" <?= ($jellyfinPassword ?? '') === '' ? 'disabled' : '' ?>><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 my-3">
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
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="tab-cuenta" role="tabpanel">
            <div class="section-title"><i class="bi bi-telephone"></i>Contacto y avisos</div>
            <div class="row g-2 mb-4">
                <div class="col-md-6">
                    <label class="form-label small"><i class="bi bi-telegram me-1"></i>Telegram Chat ID</label>
                    <input type="text" id="telegramChatId" class="form-control form-control-sm" value="<?= e((string) ($mediaUser->telegram_chat_id ?? '')) ?>" placeholder="Ej. 2023182976">
                    <div class="form-text">El cliente también puede vincularlo solo en el portal → Mi ficha (código de un uso).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small"><i class="bi bi-whatsapp me-1"></i>WhatsApp</label>
                    <input type="text" id="whatsappPhone" class="form-control form-control-sm" value="<?= e($mediaUser->metaGet('whatsapp_phone') ?? '') ?>" placeholder="Ej. 34612345678 (con código de país, sin +)">
                    <div class="form-text">Avisos automáticos a clientes: WhatsApp Cloud API en Configuración.</div>
                </div>
            </div>

            <div class="section-title"><i class="bi bi-journal-text"></i>Notas privadas</div>
            <textarea id="userNotes" class="form-control form-control-sm mb-4" rows="4" placeholder="Ej: cliente habitual, pagó por Bizum el día 3, tuvo problema de buffering…"><?= e($mediaUser->notes ?? '') ?></textarea>

            <div class="section-title"><i class="bi bi-tools"></i>Acciones avanzadas</div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-info btn-sm" id="btnDiscoverIdentity" title="Buscar email/usuario en servidor, clientes o registros previos">
                    <i class="bi bi-search me-1"></i>Buscar email / usuario
                </button>
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
        </div>

        <div class="tab-pane fade" id="tab-actividad" role="tabpanel">
            <div class="section-title"><i class="bi bi-router"></i>IPs y dispositivos</div>
            <p class="small text-muted mb-3">Al marcar hogar o fuera se aplica a toda la IP: un iPhone en la misma IP que la tele también cuenta como hogar.</p>
            <div class="table-responsive mb-4">
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

            <div id="playback-history-card">
                <div class="section-title d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-collection-play"></i>Historial de reproducción</span>
                    <?php if ($playbackHistoryTotal > 0): ?>
                    <span class="badge bg-secondary" id="playback-history-count"><?= $playbackHistoryTotal ?></span>
                    <?php endif; ?>
                </div>
                <p class="small text-muted mb-3">Se registra al ver contenido en directo o en sincronización del servidor.</p>
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
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="playback-history-more"
                            data-page="1" data-limit="40">
                        Cargar más
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <hr class="my-4">

            <div class="section-title d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i>Historial de actividad</span>
                <a href="/media-users/activity" class="small">Ver global</a>
            </div>
            <div class="list-group list-group-flush border rounded" style="max-height: 420px; overflow-y: auto;">
                <?php if ($timeline === []): ?>
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

        <div class="tab-pane fade" id="tab-comunicacion" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div id="portal-link" class="h-100">
                        <div class="section-title"><i class="bi bi-link-45deg"></i>Enlace al portal (sin contraseña)</div>
                        <p class="small text-muted mb-2">
                            El cliente abre el enlace y entra directo a su cuenta. Quien tenga el enlace entra como este usuario.
                            La URL completa solo se muestra al crearla.
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

                <div class="col-lg-6">
                    <div id="stripe" class="h-100">
                        <div class="section-title"><i class="bi bi-credit-card"></i>Cobro con Stripe</div>
                        <p class="small text-muted mb-2">Genera un enlace de pago para que el cliente renueve. Al confirmar el cobro, se suman los días automáticamente.</p>

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
            </div>

            <hr class="my-4">

            <div class="section-title"><i class="bi bi-send"></i>Enviar mensaje</div>
            <div class="row g-3 mb-4">
                <div class="col-lg-8">
                    <input type="text" id="msgTitle" class="form-control form-control-sm mb-2" value="Aviso" placeholder="Título">
                    <textarea id="msgBody" class="form-control form-control-sm mb-2" rows="4" placeholder="Mensaje…"></textarea>
                    <p class="small text-muted mb-0">Variables: {username}, {email}, {display_name}, {end_date}</p>
                </div>
                <div class="col-lg-4 d-flex flex-column gap-2">
                    <button type="button" class="btn btn-outline-info btn-sm" id="btnSendMsg"><i class="bi bi-telegram me-1"></i>Enviar por Telegram</button>
                    <button type="button" class="btn btn-outline-success btn-sm" id="btnSendMsgWhatsapp"><i class="bi bi-whatsapp me-1"></i>Enviar por WhatsApp</button>
                </div>
            </div>

            <div class="section-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-inbox"></i>Historial de avisos</span>
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
                    <?php if ($messages === []): ?>
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
