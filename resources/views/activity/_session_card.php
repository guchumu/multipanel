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
    '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="120" viewBox="0 0 80 120">'
    . '<rect width="80" height="120" fill="#2b2f36"/>'
    . '<text x="40" y="64" fill="#9aa0a6" text-anchor="middle" font-family="sans-serif" font-size="9">N/A</text>'
    . '</svg>'
);

$mediaUserUuid = trim((string) ($session['media_user_uuid'] ?? ''));
$sessionTitle = (string) ($session['title'] ?? 'Sin título');
$canKill = !empty($session['can_kill']);
$playMethod = (string) ($session['play_method'] ?? '');
$isTranscode = $playMethod === 'transcode';
$sessionKey = (string) ($session['session_id'] ?? '');
$progress = (int) ($session['progress'] ?? 0);
$state = (string) ($session['state'] ?? '');

$streamInfo = is_array($session['stream_info'] ?? null) ? $session['stream_info'] : [];
$streamRows = [
    ['Q', 'Quality', (string) ($streamInfo['quality'] ?? '')],
    ['S', 'Stream', (string) ($streamInfo['stream'] ?? '')],
    ['C', 'Container', (string) ($streamInfo['container'] ?? '')],
    ['V', 'Video', (string) ($streamInfo['video'] ?? $session['video_label'] ?? $session['video_decision'] ?? '')],
    ['A', 'Audio', (string) ($streamInfo['audio'] ?? $session['audio_label'] ?? $session['audio_decision'] ?? '')],
    ['Sub', 'Subtitle', (string) ($streamInfo['subtitle'] ?? 'None')],
];
$methodLabel = $playMethodLabel($playMethod);
$streamLine = trim((string) ($streamInfo['stream'] ?? ''));
$streamSummaryParts = array_values(array_filter([
    $streamLine !== '' ? $streamLine : $methodLabel,
    trim((string) ($streamInfo['quality'] ?? '')),
    trim((string) ($streamInfo['video'] ?? $session['video_label'] ?? $session['video_decision'] ?? '')),
    trim((string) ($streamInfo['audio'] ?? $session['audio_label'] ?? $session['audio_decision'] ?? '')),
    trim((string) ($streamInfo['subtitle'] ?? '')),
], static fn ($p) => $p !== '' && $p !== '—'));
$streamSummary = implode(' · ', $streamSummaryParts);
$hasStreamDetail = false;
foreach ($streamRows as [, , $value]) {
    if (trim($value) !== '') {
        $hasStreamDetail = true;
        break;
    }
}
$streamInfoClass = 'session-stream-info'
    . ($isTranscode ? ' session-stream-info--transcode expanded' : '');
?>
<div class="col-12 col-sm-6 col-lg-4 col-xl-3 session-col">
    <div class="session-card session-row<?= !empty($session['over_limit']) ? ' session-row--over-limit' : '' ?>"
         data-session-id="<?= e($sessionKey) ?>"
         data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>"
         data-play-method="<?= e($playMethod) ?>">
        <div class="session-poster">
            <?php if ($thumbUrl !== ''): ?>
            <img src="<?= e($thumbUrl) ?>" alt="" decoding="async"
                 onerror="this.onerror=null;this.src='<?= e($thumbFallback) ?>';">
            <?php else: ?>
            <div class="session-poster-fallback"><i class="bi bi-film"></i></div>
            <?php endif; ?>
        </div>
        <div class="session-main">
            <div class="session-head">
                <p class="session-meta-line mb-0">
                    <?php if ($mediaUserUuid !== ''): ?>
                    <a href="/media-users/<?= e($mediaUserUuid) ?>" class="session-user-link text-decoration-none"><?= e($session['user'] ?? '-') ?></a>
                    <?php else: ?>
                    <span class="session-user-name"><?= e($session['user'] ?? '-') ?></span>
                    <?php endif; ?>
                    <span class="session-meta-sep">·</span>
                    <span class="session-meta-device"><?= e($session['player'] ?? '-') ?><?php if (!empty($session['platform'])): ?> <span class="session-meta-platform">(<?= e($session['platform']) ?>)</span><?php endif; ?></span>
                    <?php if (!empty($session['over_limit'])): ?>
                    <span class="badge bg-danger session-limit-badge" title="Supera el límite (IPs o sesiones distintas: <?= (int) ($session['user_stream_count'] ?? 0) ?>/<?= (int) ($session['stream_limit'] ?? 0) ?>)">
                        Límite <?= (int) ($session['user_stream_count'] ?? 0) ?>/<?= (int) ($session['stream_limit'] ?? 0) ?>
                    </span>
                    <?php endif; ?>
                </p>
                <div class="session-head-actions">
                    <span class="badge bg-<?= $playMethodBadge($playMethod) ?> session-method-badge"><?= e($methodLabel) ?></span>
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
            </div>

            <h6 class="session-title text-truncate mb-0"
                role="button"
                tabindex="0"
                aria-expanded="false"
                title="<?= e($sessionTitle) ?> — clic para ver completo">
                <?= e($sessionTitle !== '' ? $sessionTitle : 'Sin título') ?>
            </h6>
            <?php if (!empty($session['subtitle'])): ?>
            <p class="session-subtitle text-truncate mb-0"><?= e($session['subtitle']) ?></p>
            <?php endif; ?>

            <div class="session-progress-row">
                <div class="progress session-progress">
                    <div class="progress-bar<?= $isTranscode ? ' session-progress-bar--transcode' : '' ?>" style="width: <?= $progress ?>%"></div>
                </div>
                <span class="session-progress-meta">
                    <?php if ($state !== ''): ?><span class="session-state"><?= e($state) ?></span><span class="session-meta-sep">·</span><?php endif; ?>
                    <span class="session-pct"><?= $progress ?>%</span>
                </span>
            </div>

            <?php if ($streamSummary !== '' || $hasStreamDetail): ?>
            <div class="<?= e($streamInfoClass) ?>"
                role="button"
                tabindex="0"
                aria-expanded="<?= $isTranscode ? 'true' : 'false' ?>"
                title="<?= $isTranscode ? 'Detalle de Transcode' : 'Clic para ver el detalle completo' ?>">
                <?php if ($streamSummary !== ''): ?>
                <div class="session-stream-summary" title="<?= e($streamSummary) ?>"><?= e($streamSummary) ?></div>
                <?php endif; ?>
                <div class="session-stream-detail">
                    <?php foreach ($streamRows as [$short, $full, $value]): ?>
                    <?php if (trim($value) === '') { continue; } ?>
                    <div class="session-stream-row">
                        <span class="session-stream-key" title="<?= e($full) ?>"><?= e($short) ?></span>
                        <span class="session-stream-val" title="<?= e($value) ?>"><?= e($value) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$isTranscode): ?>
                <span class="stream-info-toggle" aria-hidden="true">Ver más</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <p class="session-foot mb-0">
                <?php if (!empty($session['client_ip'])): ?>
                <code class="session-ip"><?= e((string) $session['client_ip']) ?></code>
                <span class="session-meta-sep">·</span>
                <?php endif; ?>
                <span class="session-server"><?= e($session['server_name'] ?? '-') ?></span>
                <?php if (!empty($session['server_type'])): ?>
                <span class="session-meta-sep">·</span>
                <span class="session-server-type"><?= e(strtoupper((string) $session['server_type'])) ?></span>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>
