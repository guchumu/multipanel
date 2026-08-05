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
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300" viewBox="0 0 200 300">'
    . '<rect width="200" height="300" fill="#2b2f36"/>'
    . '<text x="100" y="150" fill="#9aa0a6" text-anchor="middle" font-family="sans-serif" font-size="14">Sin carátula</text>'
    . '</svg>'
);

$mediaUserUuid = trim((string) ($session['media_user_uuid'] ?? ''));
$sessionTitle = (string) ($session['title'] ?? 'Sin título');
$canKill = !empty($session['can_kill']);
$playMethod = (string) ($session['play_method'] ?? '');
$isTranscode = $playMethod === 'transcode';
$sessionKey = (string) ($session['session_id'] ?? '');
?>
<div class="col-12 col-md-6 col-xxl-4">
    <div class="card border-0 shadow-sm h-100 session-card session-card-horizontal<?= !empty($session['over_limit']) ? ' border border-danger' : '' ?>"
         data-session-id="<?= e($sessionKey) ?>"
         data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>"
         data-play-method="<?= e($playMethod) ?>">
        <div class="session-card-inner">
            <div class="session-poster">
                <?php if ($thumbUrl !== ''): ?>
                <img src="<?= e($thumbUrl) ?>" alt="" decoding="async"
                     onerror="this.onerror=null;this.src='<?= e($thumbFallback) ?>';">
                <?php else: ?>
                <div class="session-poster-fallback"><i class="bi bi-film fs-1"></i></div>
                <?php endif; ?>
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
            <div class="card-body session-card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                    <span class="badge bg-<?= $playMethodBadge($playMethod) ?>">
                        <?= e($playMethodLabel($playMethod)) ?>
                    </span>
                    <span class="badge bg-secondary"><?= e(strtoupper($session['server_type'] ?? '')) ?></span>
                </div>
                <?php if (!empty($session['over_limit'])): ?>
                <div class="mb-2">
                    <span class="badge bg-danger" title="Supera el límite (IPs o sesiones distintas: <?= (int) ($session['user_stream_count'] ?? 0) ?>/<?= (int) ($session['stream_limit'] ?? 0) ?>)">
                        <i class="bi bi-exclamation-octagon me-1"></i>Límite <?= (int) ($session['user_stream_count'] ?? 0) ?>/<?= (int) ($session['stream_limit'] ?? 0) ?>
                    </span>
                </div>
                <?php endif; ?>
                <h6 class="card-title session-title mb-1 text-truncate"
                    role="button"
                    tabindex="0"
                    aria-expanded="false"
                    title="<?= e($sessionTitle) ?> — clic para ver completo">
                    <?= e($sessionTitle !== '' ? $sessionTitle : 'Sin título') ?>
                </h6>
                <?php if (!empty($session['subtitle'])): ?>
                <p class="small text-muted mb-2 text-truncate"><?= e($session['subtitle']) ?></p>
                <?php endif; ?>
                <p class="small mb-1">
                    <i class="bi bi-person me-1"></i><?php if ($mediaUserUuid !== ''): ?><a href="/media-users/<?= e($mediaUserUuid) ?>" class="session-user-link text-decoration-none"><?= e($session['user'] ?? '-') ?></a><?php else: ?><?= e($session['user'] ?? '-') ?><?php endif; ?>
                </p>
                <?php if (!empty($session['client_ip'])): ?>
                <p class="small mb-1"><i class="bi bi-geo-alt me-1"></i><code><?= e((string) $session['client_ip']) ?></code></p>
                <?php endif; ?>
                <p class="small mb-1"><i class="bi bi-hdd-network me-1"></i><?= e($session['server_name'] ?? '-') ?></p>
                <p class="small mb-2"><i class="bi bi-display me-1"></i><?= e($session['player'] ?? '-') ?>
                    <?php if (!empty($session['platform'])): ?>
                    <span class="text-muted">(<?= e($session['platform']) ?>)</span>
                    <?php endif; ?>
                </p>
                <?php
                $streamInfo = is_array($session['stream_info'] ?? null) ? $session['stream_info'] : [];
                $streamRows = [
                    ['Q', 'Quality', (string) ($streamInfo['quality'] ?? '')],
                    ['S', 'Stream', (string) ($streamInfo['stream'] ?? '')],
                    ['C', 'Container', (string) ($streamInfo['container'] ?? '')],
                    ['V', 'Video', (string) ($streamInfo['video'] ?? $session['video_label'] ?? $session['video_decision'] ?? '')],
                    ['A', 'Audio', (string) ($streamInfo['audio'] ?? $session['audio_label'] ?? $session['audio_decision'] ?? '')],
                    ['Sub', 'Subtitle', (string) ($streamInfo['subtitle'] ?? 'None')],
                ];
                $streamInfoClass = 'session-stream-info small mb-2'
                    . ($isTranscode ? ' session-stream-info--transcode expanded' : '');
                ?>
                <dl class="<?= e($streamInfoClass) ?>"
                    role="button"
                    tabindex="0"
                    aria-expanded="<?= $isTranscode ? 'true' : 'false' ?>"
                    title="<?= $isTranscode ? 'Detalle de Transcode' : 'Clic para ver el detalle completo' ?>">
                    <?php foreach ($streamRows as [$short, $full, $value]): ?>
                    <?php if (trim($value) === '') { continue; } ?>
                    <div class="session-stream-row">
                        <dt title="<?= e($full) ?>"><?= e($short) ?></dt>
                        <dd title="<?= e($value) ?>"><?= e($value) ?></dd>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$isTranscode): ?>
                    <span class="stream-info-toggle" aria-hidden="true">Ver más</span>
                    <?php endif; ?>
                </dl>
                <?php $progress = (int) ($session['progress'] ?? 0); ?>
                <div class="progress mb-1" style="height: 4px;">
                    <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                </div>
                <div class="d-flex justify-content-between align-items-center small text-muted">
                    <span><?= e($session['state'] ?? '') ?></span>
                    <span><?= $progress ?>%</span>
                </div>
            </div>
        </div>
    </div>
</div>
