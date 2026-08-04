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
?>
<div class="col-sm-6 col-lg-4 col-xl-3">
    <div class="card border-0 shadow-sm h-100 session-card" data-session-id="<?= e((string) ($session['session_id'] ?? '')) ?>" data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>">
        <div class="session-poster rounded-top">
            <?php if ($thumbUrl !== ''): ?>
            <img src="<?= e($thumbUrl) ?>" alt="" decoding="async"
                 onerror="this.onerror=null;this.src='<?= e($thumbFallback) ?>';">
            <?php else: ?>
            <div class="session-poster-fallback"><i class="bi bi-film fs-1"></i></div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                <span class="badge bg-<?= $playMethodBadge($session['play_method'] ?? '') ?>">
                    <?= e($playMethodLabel($session['play_method'] ?? '')) ?>
                </span>
                <span class="badge bg-secondary"><?= e(strtoupper($session['server_type'] ?? '')) ?></span>
            </div>
            <h6 class="card-title mb-1 text-truncate" title="<?= e($session['title'] ?? '') ?>">
                <?= e($session['title'] ?? 'Sin título') ?>
            </h6>
            <?php if (!empty($session['subtitle'])): ?>
            <p class="small text-muted mb-2 text-truncate"><?= e($session['subtitle']) ?></p>
            <?php endif; ?>
            <p class="small mb-1"><i class="bi bi-person me-1"></i><?= e($session['user'] ?? '-') ?></p>
            <p class="small mb-1"><i class="bi bi-hdd-network me-1"></i><?= e($session['server_name'] ?? '-') ?></p>
            <p class="small mb-2"><i class="bi bi-display me-1"></i><?= e($session['player'] ?? '-') ?>
                <?php if (!empty($session['platform'])): ?>
                <span class="text-muted">(<?= e($session['platform']) ?>)</span>
                <?php endif; ?>
            </p>
            <div class="small mb-2 text-muted">
                <span class="me-2"><i class="bi bi-camera-video me-1"></i>Vídeo: <strong><?= e($session['video_label'] ?? $session['video_decision'] ?? '-') ?></strong></span>
                <span><i class="bi bi-music-note-beamed me-1"></i>Audio: <strong><?= e($session['audio_label'] ?? $session['audio_decision'] ?? '-') ?></strong></span>
            </div>
            <?php $progress = (int) ($session['progress'] ?? 0); ?>
            <div class="progress mb-1" style="height: 4px;">
                <div class="progress-bar" style="width: <?= $progress ?>%"></div>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-muted">
                <span><?= e($session['state'] ?? '') ?></span>
                <span><?= $progress ?>%</span>
            </div>
            <?php if (!empty($session['can_kill'])): ?>
            <button type="button" class="btn btn-outline-danger btn-sm w-100 mt-2 btn-kill-session"
                    data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>"
                    data-session-id="<?= e((string) ($session['session_id'] ?? '')) ?>">
                <i class="bi bi-stop-circle me-1"></i>Detener reproducción
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
