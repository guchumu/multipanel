<?php
$dateFmt = static function (?string $d): string {
    if ($d === null || $d === '') {
        return '—';
    }
    $ts = strtotime(substr($d, 0, 10));
    return $ts === false ? e($d) : date('d/m/Y', $ts);
};
$shopOptions = is_array($shopOptions ?? null) ? $shopOptions : [];
$canPay = !empty($stripeConfigured) && $shopOptions !== [];
$buyerEmail = trim((string) ($portalUser->email ?? ''));
$included = (int) ($includedStreams ?? 2);
$extraAccount = (float) ($extraAccountPrice ?? 50);
$extraStreamMonth = (float) ($extraStreamMonthly ?? 4);
ob_start();
?>
<div class="ez-shop-page">
<h1 class="ez-page-title">Elige lo que contratas</h1>
<p class="ez-page-lead">Cuenta individual, reproducciones en casa, y el tiempo. El total se calcula solo.</p>

<?php if (!empty($expiry['date'])): ?>
<p class="ez-now-until">Ahora mismo puedes ver hasta el <strong><?= $dateFmt($expiry['date']) ?></strong>.</p>
<?php endif; ?>

<?php include base_path('resources/views/portal/_shop_guide.php'); ?>

<?php if ($canPay): ?>
<form method="POST" action="/portal/payment/renew" id="ez-shop" class="ez-shop"
      data-included="<?= (int) $included ?>"
      data-extra-account="<?= e((string) $extraAccount) ?>"
      data-extra-stream-month="<?= e((string) $extraStreamMonth) ?>"
      data-buyer-email="<?= e($buyerEmail) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="months" id="ez-months" value="<?= (int) ($shopOptions[0]['months'] ?? 1) ?>">
    <input type="hidden" name="server_id" id="ez-server" value="<?= (int) ($selectedServerId ?? 0) ?>">

    <section class="card portal-card ez-step">
        <div class="card-body">
            <h2 class="ez-step-title"><span>1</span> ¿Plex o Jellyfin?</h2>
            <p class="ez-help">Elige dónde van esta cuenta y las extra.<?= !empty($portalUser->server_id) ? ' Tu cuenta actual queda donde está; las nuevas van al que elijas.' : '' ?></p>
            <?php $shopServers = is_array($shopServers ?? null) ? $shopServers : []; ?>
            <?php if ($shopServers === []): ?>
            <p class="ez-help mb-0">Ahora mismo no hay servidor Plex/Jellyfin en el panel. Puedes comprar el tiempo igual.</p>
            <?php else: ?>
            <div class="ez-chips ez-chips-service" role="group" aria-label="Servicio">
                <?php foreach ($shopServers as $srv): ?>
                <button type="button" class="ez-chip<?= (int) $srv['id'] === (int) ($selectedServerId ?? 0) ? ' is-on' : '' ?>"
                        data-server-id="<?= (int) $srv['id'] ?>">
                    <strong><?= e($srv['type'] === 'jellyfin' ? 'Jellyfin' : 'Plex') ?></strong>
                    <span><?= e($srv['name'] !== '' ? $srv['name'] : 'Servidor') ?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card portal-card ez-step">
        <div class="card-body">
            <h2 class="ez-step-title"><span>2</span> ¿Cuántos meses?</h2>
            <p class="ez-help">Elige una duración. El precio es el de Facturación y va en la primera cuenta.</p>
            <div class="ez-chips" role="group" aria-label="Meses">
                <?php foreach ($shopOptions as $i => $opt): ?>
                <button type="button" class="ez-chip<?= $i === 0 ? ' is-on' : '' ?>"
                        data-months="<?= (int) $opt['months'] ?>"
                        data-price="<?= e((string) $opt['price']) ?>"
                        data-days="<?= (int) $opt['days'] ?>">
                    <strong><?= e($opt['label']) ?></strong>
                    <span><?= number_format((float) $opt['price'], 2, ',', '.') ?> €</span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card portal-card ez-step">
        <div class="card-body">
            <h2 class="ez-step-title"><span>3</span> Cuentas y reproducciones</h2>
            <p class="ez-help mb-2">
                Añade filas si hace falta otra cuenta. En cada una, sube las reproducciones si hay más teles <strong>en esa casa</strong>.
            </p>
            <div class="ez-live" id="ez-live" aria-live="polite"></div>
            <div class="ez-table-wrap">
                <table class="ez-shop-table" id="ez-shop-table">
                    <thead>
                        <tr>
                            <th>Cuenta</th>
                            <th>Email</th>
                            <th>Reproducciones</th>
                            <th>Extra</th>
                            <th>Importe</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ez-accounts"></tbody>
                    <tfoot id="ez-ticket-foot"></tfoot>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary mt-3" id="ez-add-account">
                Añadir cuenta individual (+ <?= number_format($extraAccount, 2, ',', '.') ?> €)
            </button>
        </div>
    </section>

    <section class="card portal-card ez-ticket">
        <div class="card-body">
            <h2 class="ez-step-title">Tu ticket</h2>
            <p class="ez-contract" id="ez-contract"></p>
            <ul class="ez-ticket-list" id="ez-ticket-list"></ul>
            <p class="ez-total">Total a pagar: <strong id="ez-total">0 €</strong></p>
            <button type="submit" class="ez-btn-big w-100" id="ez-pay">Pagar y listo</button>
            <p class="ez-help text-center mb-0 mt-2">Te llevamos a la tarjeta. Cuando pagues, se suma el tiempo.</p>
        </div>
    </section>
</form>
<?php else: ?>
<div class="card portal-card">
    <div class="card-body">
        <p class="mb-2">Ahora mismo no se puede pagar aquí.</p>
        <a href="/portal/tickets/create" class="ez-btn-big">Pedir ayuda</a>
    </div>
</div>
<?php endif; ?>

<p class="text-center mt-3 mb-0"><a class="link-light" href="/portal">← Volver</a></p>
</div>
<?php
$content = ob_get_clean();
$scripts = '<script src="' . e(asset('js/portal-shop.js')) . '?v=' . (@filemtime(public_path('assets/js/portal-shop.js')) ?: '1') . '"></script>';
include base_path('resources/views/layouts/portal.php');
?>
