<?php
$dateFmt = static function (?string $d): string {
    if ($d === null || $d === '') {
        return '—';
    }
    $ts = strtotime(substr($d, 0, 10));
    if ($ts === false) {
        return e($d);
    }
    return date('d/m/Y', $ts);
};
$serverInfo = is_array($serverInfo ?? null) ? $serverInfo : ['name' => '—', 'type_label' => '—'];
$maxStreams = (int) ($portalUser->max_streams ?? 0);
if ($maxStreams <= 0) {
    $maxStreams = 2;
}
$canPay = !empty($stripeConfigured) && !empty($shopOptions);
$heroEmoji = match ($accountStatus['class'] ?? '') {
    'danger' => '😮',
    'warning' => '⏰',
    default => '🎬',
};
ob_start();
?>
<section class="ez-hero ez-hero--<?= e($accountStatus['class'] ?? 'success') ?>">
    <p class="ez-kicker">Tu cine en casa <?= $heroEmoji ?></p>
    <h1 class="ez-hello">Hola, <?= e($portalUser->display_name ?: ($portalUser->username ?? 'amigo')) ?></h1>
    <p class="ez-hint"><?= e($accountStatus['hint'] ?? 'Todo listo para ver.') ?></p>
    <p class="ez-hint ez-hint-share">Cada cuenta individual tiene su historial. Si la compartes, todos veis lo mismo.</p>
</section>

<div class="ez-facts">
    <div class="ez-fact">
        <span class="ez-fact-ico" aria-hidden="true">📅</span>
        <span class="ez-fact-label">Puedes ver hasta</span>
        <strong class="ez-fact-value"><?= !empty($expiry['date']) ? $dateFmt($expiry['date']) : '—' ?></strong>
        <span class="ez-fact-sub"><?= e($expiry['label'] ?? '') ?></span>
    </div>
    <div class="ez-fact">
        <span class="ez-fact-ico" aria-hidden="true">📺</span>
        <span class="ez-fact-label">Visionados a la vez</span>
        <strong class="ez-fact-value"><?= (int) $maxStreams ?></strong>
        <span class="ez-fact-sub">2 van incluidos · misma cuenta, mismo hogar</span>
    </div>
    <div class="ez-fact">
        <span class="ez-fact-ico" aria-hidden="true">🏠</span>
        <span class="ez-fact-label">Dónde ves</span>
        <strong class="ez-fact-value text-truncate"><?= e($serverInfo['type_label'] ?? '—') ?></strong>
        <span class="ez-fact-sub text-truncate"><?= e($serverInfo['name'] ?? '') ?></span>
    </div>
</div>

<div class="ez-cta-wrap">
    <?php if ($canPay): ?>
    <a href="/portal/subscription" class="ez-btn-big">Quiero más tiempo ✨</a>
    <?php else: ?>
    <a href="/portal/tickets/create" class="ez-btn-big">Pedir ayuda</a>
    <?php endif; ?>
</div>

<nav class="ez-quick" aria-label="Cosas que puedes hacer">
    <a href="/portal/subscription" class="ez-quick-item"><span>🛒</span>Comprar</a>
    <a href="/portal/peticiones" class="ez-quick-item"><span>🍿</span>Pedir peli</a>
    <a href="/portal/tickets" class="ez-quick-item"><span>💬</span>Ayuda</a>
    <a href="/portal/profile" class="ez-quick-item"><span>👤</span>Mi ficha</a>
</nav>

<?php if (!empty($needsMessagingLink)): ?>
<div class="card portal-card mt-3">
    <div class="card-body">
        <p class="mb-2 fw-bold">¿Quieres avisos en el móvil?</p>
        <p class="small text-muted mb-3">Vincula Telegram (un toque) o guarda tu WhatsApp en Mi ficha. Así te avisamos cuando se acaba el tiempo.</p>
        <a href="/portal/profile" class="btn btn-primary">Activar avisos 📣</a>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($liveStreams)): ?>
<div class="card portal-card mt-3">
    <div class="card-body">
        <h2 class="portal-section-title">Ahora mismo se está viendo</h2>
        <ul class="list-unstyled mb-0 ez-now">
            <?php foreach (array_slice($liveStreams, 0, 4) as $s): ?>
            <li>▶️ <?= e($s['title'] ?? $s['media_title'] ?? 'Reproducción') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
