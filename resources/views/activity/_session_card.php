<?php
/** @var array<string, mixed> $session */
/** @var callable $playMethodLabel */
/** @var callable $playMethodBadge */

// Misma URL que thumbs-debug: /activity/thumb/{uuid}?p=base64url
$thumbUrl = (string) ($session['thumb_url'] ?? '');
if ($thumbUrl !== '' && !str_contains($thumbUrl, '/activity/thumb/')) {
    // Nunca pintar URL directa al PMS (mixed content + token).
    $thumbUrl = '';
}
if ($thumbUrl === '' && !empty($session['art_path']) && !empty($session['server_uuid'])) {
    $thumbUrl = '/activity/thumb/' . (string) $session['server_uuid']
        . '?p=' . \App\Services\StreamingActivityService::encodeThumbParam((string) $session['art_path']);
} elseif ($thumbUrl === '' && !empty($session['item_id']) && !empty($session['server_uuid'])) {
    $thumbUrl = '/activity/thumb/' . (string) $session['server_uuid']
        . '?item=' . rawurlencode((string) $session['item_id']);
} elseif ($thumbUrl !== '' && str_contains($thumbUrl, '?path=')) {
    // Legacy → ?p=
    $parts = parse_url($thumbUrl);
    $pathQuery = [];
    parse_str((string) ($parts['query'] ?? ''), $pathQuery);
    if (!empty($pathQuery['path']) && !empty($session['server_uuid'])) {
        $thumbUrl = '/activity/thumb/' . (string) $session['server_uuid']
            . '?p=' . \App\Services\StreamingActivityService::encodeThumbParam((string) $pathQuery['path']);
    }
}

$thumbFallback = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="225" viewBox="0 0 150 225">'
    . '<rect width="150" height="225" fill="#1a1a1a"/>'
    . '<text x="75" y="118" fill="#666" text-anchor="middle" font-family="sans-serif" font-size="12">N/A</text>'
    . '</svg>'
);

$mediaUserUuid = trim((string) ($session['media_user_uuid'] ?? ''));
$sessionTitle = (string) ($session['title'] ?? 'Sin título');
$sessionSubtitle = trim((string) ($session['subtitle'] ?? ''));
$year = trim((string) ($session['year'] ?? ''));
$canKill = !empty($session['can_kill']);
$playMethod = (string) ($session['play_method'] ?? '');
$isTranscode = $playMethod === 'transcode';
$sessionKey = (string) ($session['session_id'] ?? '');
$progress = max(0, min(100, (int) ($session['progress'] ?? 0)));
$state = strtolower(trim((string) ($session['state'] ?? '')));
$mediaType = strtolower(trim((string) ($session['media_type'] ?? '')));
$platform = trim((string) ($session['platform'] ?? ''));
$product = trim((string) ($session['product'] ?? ''));
$player = trim((string) ($session['player'] ?? ''));
$location = trim((string) ($session['location'] ?? ''));
$bandwidth = trim((string) ($session['bandwidth'] ?? ''));
$clientIp = trim((string) ($session['client_ip'] ?? ''));
$userName = (string) ($session['user'] ?? '-');

$streamInfo = is_array($session['stream_info'] ?? null) ? $session['stream_info'] : [];
$quality = trim((string) ($streamInfo['quality'] ?? ''));
$streamLine = trim((string) ($streamInfo['stream'] ?? ''));
$container = trim((string) ($streamInfo['container'] ?? ''));
$videoLine = trim((string) ($streamInfo['video'] ?? $session['video_label'] ?? $session['video_decision'] ?? ''));
$audioLine = trim((string) ($streamInfo['audio'] ?? $session['audio_label'] ?? $session['audio_decision'] ?? ''));
$subtitleLine = trim((string) ($streamInfo['subtitle'] ?? 'None'));
$methodLabel = $playMethodLabel($playMethod);
if ($streamLine === '') {
    $streamLine = $methodLabel;
}

$platformLabel = $platform !== '' ? $platform : ($product !== '' ? $product : 'Plex');
$platformInitial = mb_strtoupper(mb_substr($platformLabel, 0, 1));

$stateIcon = match ($state) {
    'paused' => 'bi-pause-fill',
    'buffering' => 'bi-arrow-repeat',
    'error' => 'bi-exclamation-triangle-fill',
    default => 'bi-play-fill',
};

$mediaIcon = match (true) {
    in_array($mediaType, ['episode', 'show', 'series'], true) => 'bi-tv',
    in_array($mediaType, ['track', 'audio', 'music'], true) => 'bi-music-note-beamed',
    in_array($mediaType, ['photo'], true) => 'bi-image',
    default => 'bi-film',
};

$metaSecondary = $sessionSubtitle !== '' ? $sessionSubtitle : ($year !== '' ? $year : '');
$household = (($session['household'] ?? '') === 'home') ? 'home' : 'away';
$householdLabel = $household === 'home' ? 'Casa' : 'Fuera';
$householdClass = $household === 'home' ? 'bg-success' : 'bg-warning text-dark';
$locationLine = $location !== '' ? $location : strtoupper((string) ($session['server_type'] ?? ''));
if ($clientIp !== '') {
    $locationLine = ($locationLine !== '' ? $locationLine . ': ' : '') . $clientIp;
}
$bgStyle = $thumbUrl !== ''
    ? 'background-image: url(' . e($thumbUrl) . ');'
    : '';

$infoRowsPrimary = [
    ['Product', $product !== '' ? $product : ($platform !== '' ? $platform : '—')],
    ['Player', $player !== '' ? $player : '—'],
    ['Quality', $quality !== '' ? $quality : '—'],
];
$infoRowsStream = [
    ['Stream', $streamLine, $isTranscode],
    ['Container', $container !== '' ? $container : '—', false],
    ['Video', $videoLine !== '' ? $videoLine : '—', $isTranscode && stripos($videoLine, 'Transcode') !== false],
    ['Audio', $audioLine !== '' ? $audioLine : '—', false],
    ['Subtitle', $subtitleLine !== '' ? $subtitleLine : 'None', false],
];
$infoRowsFoot = [
    ['Dónde', $householdLabel . ($locationLine !== '' ? ' · ' . $locationLine : '')],
    ['Bandwidth', $bandwidth !== '' ? $bandwidth : ((string) ($session['server_name'] ?? '—'))],
];
?>
<div class="col-12 col-lg-6 col-xxl-4 session-col">
    <div class="session-card session-row<?= !empty($session['over_limit']) ? ' session-row--over-limit' : '' ?><?= $isTranscode ? ' session-row--transcode' : '' ?>"
         data-session-id="<?= e($sessionKey) ?>"
         data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>"
         data-play-method="<?= e($playMethod) ?>">
        <div class="session-activity-container">
            <div class="session-activity-background" style="<?= $bgStyle ?>">
                <div class="session-poster-wrap">
                    <?php if ($thumbUrl !== ''): ?>
                    <div class="session-poster" style="background-image: url(<?= e($thumbUrl) ?>);" role="img" aria-label=""></div>
                    <img class="session-poster-img-probe" src="<?= e($thumbUrl) ?>" alt="" decoding="async"
                         onerror="this.onerror=null;var p=this.previousElementSibling;if(p){p.style.backgroundImage='url(<?= e($thumbFallback) ?>)';}">
                    <?php else: ?>
                    <div class="session-poster session-poster--fallback"><i class="bi bi-film" aria-hidden="true"></i></div>
                    <?php endif; ?>
                </div>

                <div class="session-platform-slot<?= $canKill ? '' : ' session-platform-slot--no-terminate' ?>">
                    <div class="session-platform-badge" title="<?= e($platformLabel) ?>">
                        <span class="session-platform-initial"><?= e($platformInitial) ?></span>
                        <span class="session-platform-name"><?= e(mb_strimwidth($platformLabel, 0, 10, '…')) ?></span>
                    </div>
                    <?php if ($canKill): ?>
                    <button type="button"
                            class="session-poster-sms"
                            title="Enviar mensaje / detener reproducción"
                            aria-label="Enviar mensaje o detener reproducción"
                            data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>"
                            data-session-id="<?= e($sessionKey) ?>">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                    <?php endif; ?>
                </div>

                <div class="session-info-panel<?= $isTranscode ? ' session-stream-info session-stream-info--transcode expanded' : ' session-stream-info' ?>"
                     role="button"
                     tabindex="0"
                     aria-expanded="<?= $isTranscode ? 'true' : 'false' ?>"
                     title="<?= $isTranscode ? 'Detalle de Transcode' : 'Clic para ampliar detalle' ?>">
                    <div class="session-info-scroller">
                        <ul class="session-info-list">
                            <?php foreach ($infoRowsPrimary as [$label, $value]): ?>
                            <li class="session-info-item">
                                <span class="session-info-key"><?= e($label) ?></span>
                                <span class="session-info-val"><?= e($value) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <ul class="session-info-list">
                            <?php foreach ($infoRowsStream as [$label, $value, $warn]): ?>
                            <li class="session-info-item">
                                <span class="session-info-key"><?= e($label) ?></span>
                                <span class="session-info-val<?= $warn ? ' session-info-val--warn' : '' ?>"><?= e($value) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <ul class="session-info-list">
                            <?php foreach ($infoRowsFoot as [$label, $value]): ?>
                            <li class="session-info-item">
                                <span class="session-info-key"><?= e($label) ?></span>
                                <span class="session-info-val"><?= e($value) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="session-info-time">
                        <?php if ($state !== ''): ?><span class="session-state"><?= e($state) ?></span><?php endif; ?>
                        <span class="session-pct"><?= $progress ?>%</span>
                    </div>
                </div>
            </div>

            <div class="session-activity-progress" title="<?= $progress ?>%">
                <div class="session-activity-progress-track">
                    <?php if ($isTranscode): ?>
                    <div class="session-buffer-bar" style="width: <?= min(100, $progress + 8) ?>%"><?= min(100, $progress + 8) ?>%</div>
                    <?php endif; ?>
                    <div class="session-progress-bar<?= $isTranscode ? ' session-progress-bar--transcode' : '' ?>" style="width: <?= $progress ?>%"><?= $progress ?>%</div>
                </div>
            </div>
        </div>

        <div class="session-metadata">
            <div class="session-meta-title-row">
                <span class="session-state-icon" title="<?= e($state !== '' ? $state : 'playing') ?>"><i class="bi <?= e($stateIcon) ?>" aria-hidden="true"></i></span>
                <h6 class="session-title text-truncate mb-0"
                    role="button"
                    tabindex="0"
                    aria-expanded="false"
                    title="<?= e($sessionTitle) ?> — clic para ver completo">
                    <?= e($sessionTitle !== '' ? $sessionTitle : 'Sin título') ?>
                </h6>
                <?php if (!empty($session['over_limit']) || !empty($session['would_cut'])): ?>
                <span class="badge bg-danger session-limit-badge" title="<?= e(!empty($session['cut_reason']) && $session['cut_reason'] === 'away' ? 'Otra casa / fuera' : 'Demasiadas teles en casa') ?>">
                    <?= !empty($session['cut_reason']) && $session['cut_reason'] === 'away' ? 'Otra casa' : 'De más' ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="session-meta-sub-row">
                <span class="session-media-icon" title="<?= e($mediaType !== '' ? $mediaType : 'media') ?>"><i class="bi <?= e($mediaIcon) ?>" aria-hidden="true"></i></span>
                <?php if ($metaSecondary !== ''): ?>
                <span class="session-subtitle text-truncate"><?= e($metaSecondary) ?></span>
                <?php else: ?>
                <span class="session-subtitle text-truncate"><?= e((string) ($session['server_name'] ?? '')) ?></span>
                <?php endif; ?>
                <span class="session-meta-user">
                    <span class="badge session-household-badge <?= e($householdClass) ?>"><?= e($householdLabel) ?></span>
                    <?php if ($mediaUserUuid !== ''): ?>
                    <a href="/media-users/<?= e($mediaUserUuid) ?>" class="session-user-link text-decoration-none"><?= e($userName) ?></a>
                    <?php else: ?>
                    <span class="session-user-name"><?= e($userName) ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>
