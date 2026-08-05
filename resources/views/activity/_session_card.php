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
            <?php if (!empty($session['over_limit'])): ?>
            <div class="mb-2">
                <span class="badge bg-danger" title="Supera el límite (IPs o sesiones distintas: <?= (int) ($session['user_stream_count'] ?? 0) ?>/<?= (int) ($session['stream_limit'] ?? 0) ?>)">
                    <i class="bi bi-exclamation-octagon me-1"></i>Límite <?= (int) ($session['user_stream_count'] ?? 0) ?>/<?= (int) ($session['stream_limit'] ?? 0) ?>
                </span>
            </div>
            <?php endif; ?>
            <h6 class="card-title mb-1 text-truncate" title="<?= e($session['title'] ?? '') ?>">
                <?= e($session['title'] ?? 'Sin título') ?>
            </h6>
            <?php if (!empty($session['subtitle'])): ?>
            <p class="small text-muted mb-2 text-truncate"><?= e($session['subtitle']) ?></p>
            <?php endif; ?>
            <p class="small mb-1"><i class="bi bi-person me-1"></i><?= e($session['user'] ?? '-') ?></p>
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
                'Quality' => (string) ($streamInfo['quality'] ?? ''),
                'Stream' => (string) ($streamInfo['stream'] ?? ''),
                'Container' => (string) ($streamInfo['container'] ?? ''),
                'Video' => (string) ($streamInfo['video'] ?? $session['video_label'] ?? $session['video_decision'] ?? ''),
                'Audio' => (string) ($streamInfo['audio'] ?? $session['audio_label'] ?? $session['audio_decision'] ?? ''),
                'Subtitle' => (string) ($streamInfo['subtitle'] ?? 'None'),
            ];
            ?>
            <dl class="session-stream-info small mb-2">
                <?php foreach ($streamRows as $label => $value): ?>
                <?php if (trim($value) === '') { continue; } ?>
                <div class="session-stream-row">
                    <dt><?= e($label) ?></dt>
                    <dd title="<?= e($value) ?>"><?= e($value) ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
            <?php $progress = (int) ($session['progress'] ?? 0); ?>
            <div class="progress mb-1" style="height: 4px;">
                <div class="progress-bar" style="width: <?= $progress ?>%"></div>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-muted">
                <span><?= e($session['state'] ?? '') ?></span>
                <span><?= $progress ?>%</span>
            </div>
            <?php if (!empty($session['can_kill'])): ?>
            <?php
            /** @var array<int, array{id:int,title:string,body:string,is_default:int}> $stopMessages */
            $stopMessages = $stopMessages ?? [];
            $defaultBody = '';
            foreach ($stopMessages as $preset) {
                if ((int) ($preset['is_default'] ?? 0) === 1) {
                    $defaultBody = (string) $preset['body'];
                    break;
                }
            }
            if ($defaultBody === '' && $stopMessages !== []) {
                $defaultBody = (string) ($stopMessages[0]['body'] ?? '');
            }
            ?>
            <div class="mt-2 kill-message-box">
                <select class="form-select form-select-sm mb-1 kill-preset-select" aria-label="Mensaje predefinido">
                    <option value="">Personalizado / sin mensaje</option>
                    <?php foreach ($stopMessages as $preset): ?>
                    <option value="<?= (int) $preset['id'] ?>"
                            <?= (int) ($preset['is_default'] ?? 0) === 1 ? 'selected' : '' ?>>
                        <?= e($preset['title']) ?><?= (int) ($preset['is_default'] ?? 0) === 1 ? ' ★' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <textarea class="form-control form-control-sm mb-1 kill-message-input"
                          rows="2"
                          placeholder="Mensaje al usuario (opcional)"
                          maxlength="500"><?= e($defaultBody) ?></textarea>
                <button type="button" class="btn btn-outline-danger btn-sm w-100 btn-kill-session"
                        data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>"
                        data-session-id="<?= e((string) ($session['session_id'] ?? '')) ?>">
                    <i class="bi bi-stop-circle me-1"></i>Pausar / detener
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
