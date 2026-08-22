<?php
$peticionEstado = static function (array $p): array {
    $subido = (string) ($p['subido'] ?? '0');
    $aceptado = (string) ($p['aceptado'] ?? '0');
    $activo = (string) ($p['activo'] ?? '1');
    $motivo = (int) ($p['idmotivo'] ?? 0);
    if ($subido === '1') {
        return ['label' => 'Subida', 'class' => 'success'];
    }
    if ($aceptado === '1') {
        return ['label' => 'En proceso', 'class' => 'info'];
    }
    if ($activo === '0' || $motivo > 0) {
        return ['label' => 'Denegada', 'class' => 'danger'];
    }
    return ['label' => 'Pendiente', 'class' => 'warning'];
};
$dateFmt = static function (?string $d): string {
    if ($d === null || $d === '') {
        return '';
    }
    $ts = strtotime(substr($d, 0, 16));
    return $ts === false ? e((string) $d) : date('d/m/Y H:i', $ts);
};
ob_start();
?>
<h1 class="portal-page-title">Mis peticiones</h1>
<p class="portal-page-lead">Consulta el estado de lo que has pedido o envía una petición nueva.</p>

<?php if (!empty($peticiones['configured']) && !empty($peticiones['can_submit'])): ?>
<div class="card portal-card mb-4">
    <div class="card-body">
        <h2 class="portal-section-title">Nueva petición</h2>
        <p class="small text-muted mb-3">Indica el título o pega el enlace de IMDb. Se asociará a tu usuario<?= !empty($portalUser->telegram_chat_id) ? ' y Telegram' : '' ?>.</p>
        <form method="POST" action="/portal/peticiones">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="peticion-title">Título</label>
                <input id="peticion-title" name="title" class="form-control" maxlength="255" placeholder="Ej. Nombre de la película o serie">
            </div>
            <div class="mb-3">
                <label class="form-label" for="peticion-url">URL <span class="text-muted">(IMDb u otra)</span></label>
                <input id="peticion-url" name="url" type="url" class="form-control" placeholder="https://www.imdb.com/title/tt…">
            </div>
            <button class="btn btn-primary" type="submit">Enviar petición</button>
        </form>
    </div>
</div>
<?php elseif (empty($peticiones['configured'])): ?>
<div class="alert alert-light border"><?= e($peticiones['note'] ?? 'Peticiones no disponibles.') ?></div>
<?php endif; ?>

<div class="card portal-card">
    <div class="card-header bg-white">
        <h2 class="portal-section-title mb-0">Listado</h2>
    </div>
    <?php if (!empty($peticiones['note']) && empty($peticiones['items'])): ?>
    <div class="card-body text-muted small"><?= e($peticiones['note']) ?></div>
    <?php elseif (empty($peticiones['items'])): ?>
    <div class="card-body text-muted text-center">No hay peticiones que mostrar.</div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($peticiones['items'] as $p): ?>
        <?php $st = $peticionEstado($p); ?>
        <li class="list-group-item">
            <div class="d-flex justify-content-between gap-2 align-items-start">
                <div class="min-w-0">
                    <div class="fw-semibold text-truncate"><?= e($p['nombrepeticion'] ?? 'Petición') ?></div>
                    <?php if (!empty($p['fechapeticion'])): ?>
                    <div class="small text-muted"><?= $dateFmt((string) $p['fechapeticion']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="badge text-bg-<?= e($st['class']) ?> flex-shrink-0"><?= e($st['label']) ?></span>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if (!empty($peticiones['configured'])): ?>
<p class="text-white-50 small mt-3 mb-0">
    Si no ves peticiones antiguas, puede que en la base remota no estén vinculadas a tu usuario o chat de Telegram.
    Las nuevas que envíes desde aquí quedarán asociadas.
</p>
<?php endif; ?>

<p class="text-white-50 small mt-3 mb-0"><a class="link-light" href="/portal">← Volver al inicio</a></p>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
