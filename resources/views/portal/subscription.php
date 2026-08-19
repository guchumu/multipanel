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
$discountPct = (int) ($shopDiscount ?? 40);
$included = (int) ($includedStreams ?? 2);
ob_start();
?>
<h1 class="ez-page-title">Elige cuánto tiempo ⏳</h1>
<p class="ez-page-lead">No hay planes raros. Solo meses. Cada persona trae <?= (int) $included ?> pantallas en la misma casa.</p>

<?php if (!empty($expiry['date'])): ?>
<p class="ez-now-until">Ahora mismo puedes ver hasta el <strong><?= $dateFmt($expiry['date']) ?></strong>.</p>
<?php endif; ?>

<?php if ($canPay): ?>
<form method="POST" action="/portal/payment/renew" id="ez-shop" class="ez-shop"
      data-discount="<?= (int) $discountPct ?>"
      data-included="<?= (int) $included ?>"
      data-buyer-email="<?= e($buyerEmail) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="months" id="ez-months" value="<?= (int) ($shopOptions[0]['months'] ?? 1) ?>">
    <input type="hidden" name="users" id="ez-users" value="1">
    <input type="hidden" name="extra_streams" id="ez-extra-streams" value="0">

    <section class="card portal-card ez-step">
        <div class="card-body">
            <h2 class="ez-step-title"><span>1</span> ¿Cuántos meses?</h2>
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
            <h2 class="ez-step-title"><span>2</span> ¿Cuántas personas?</h2>
            <p class="ez-help">La primera paga el precio entero. Las demás tienen un <?= (int) $discountPct ?>% de descuento.</p>
            <div class="ez-stepper">
                <button type="button" class="ez-pm" id="ez-users-minus" aria-label="Menos personas">−</button>
                <div class="ez-stepper-val"><strong id="ez-users-n">1</strong><span>persona(s)</span></div>
                <button type="button" class="ez-pm" id="ez-users-plus" aria-label="Más personas">+</button>
            </div>
            <div id="ez-emails" class="ez-emails mt-3"></div>
        </div>
    </section>

    <section class="card portal-card ez-step">
        <div class="card-body">
            <h2 class="ez-step-title"><span>3</span> ¿Más pantallas?</h2>
            <p class="ez-help">Cada persona ya trae <?= (int) $included ?> TVs en casa. Si quieres más, cuestan un <?= (int) $discountPct ?>% menos.</p>
            <div class="ez-stepper">
                <button type="button" class="ez-pm" id="ez-streams-minus" aria-label="Menos pantallas extra">−</button>
                <div class="ez-stepper-val"><strong id="ez-streams-n">0</strong><span>pantalla(s) extra</span></div>
                <button type="button" class="ez-pm" id="ez-streams-plus" aria-label="Más pantallas extra">+</button>
            </div>
        </div>
    </section>

    <section class="card portal-card ez-ticket">
        <div class="card-body">
            <h2 class="ez-step-title">Tu ticket 🧾</h2>
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
<?php
$content = ob_get_clean();
$scripts = '<script src="' . e(asset('js/portal-shop.js')) . '?v=' . (@filemtime(public_path('assets/js/portal-shop.js')) ?: '1') . '"></script>';
include base_path('resources/views/layouts/portal.php');
?>
