<?php
/** @var array<string, mixed> $session */
/** @var callable $playMethodLabel */
/** @var callable $playMethodBadge */
?>
<div class="col-sm-6 col-lg-4 col-xl-3">
    <div class="card border-0 shadow-sm h-100 session-card" data-session-id="<?= e((string) ($session['session_id'] ?? '')) ?>" data-server-id="<?= (int) ($session['server_id'] ?? 0) ?>">
        <div class="ratio ratio-2x3 bg-dark rounded-top overflow-hidden position-relative">
            <?php if (!empty($session['thumb_url'])): ?>
            <img src="<?= e($session['thumb_url']) ?>" alt="" class="object-fit-cover w-100 h-100" loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="d-none align-items-center justify-content-center h-100 text-white-50 position-absolute top-0 start-0 w-100">
                <i class="bi bi-film fs-1"></i>
            </div>
            <?php else: ?>
            <div class="d-flex align-items-center justify-content-center h-100 text-white-50">
                <i class="bi bi-film fs-1"></i>
            </div>
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
