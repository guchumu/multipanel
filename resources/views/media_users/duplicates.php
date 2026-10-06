<?php ob_start();
$pairs = is_array($pairs ?? null) ? $pairs : [];
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Posibles duplicados</h4>
        <p class="text-muted small mb-0">
            Misma persona con dos fichas (p. ej. nombre completo vs usuario Plex).
            Al fusionar se conservan email, Telegram, fecha más lejana y el username tipo handle.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/media-users/duplicates?run=1" class="btn btn-warning btn-sm"
           onclick="return confirm('¿Ejecutar auto-fusión ahora? Une email/username/Telegram coincidentes.');">
            <i class="bi bi-magic me-1"></i>Auto-fusionar ahora
        </a>
        <a href="/media-users/recent" class="btn btn-outline-secondary btn-sm">Últimos añadidos</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6 class="mb-2"><i class="bi bi-intersect me-1"></i>Fusionar a mano (sin compartir email)</h6>
        <p class="small text-muted mb-3">
            Escribe el <strong>ID</strong>, username o email de cada ficha y elige cuál conservar.
            Ejemplo: conservar <code>jordiprtl</code> y archivar <code>8655</code>.
        </p>
        <form method="post" action="/media-users/duplicates/merge" class="row g-2 align-items-end"
              onsubmit="return confirm('¿Fusionar estas dos fichas?');">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label small mb-0">Conservar (ID / usuario / email)</label>
                <input type="text" name="keep_query" class="form-control form-control-sm" required placeholder="jordiprtl o 1234">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0">Archivar (ID / usuario / email)</label>
                <input type="text" name="remove_query" class="form-control form-control-sm" required placeholder="8655">
            </div>
            <div class="col-md-4">
                <button class="btn btn-warning btn-sm"><i class="bi bi-intersect me-1"></i>Fusionar</button>
            </div>
        </form>
    </div>
</div>

<?php if ($pairs === []): ?>
<div class="alert alert-success border-0 shadow-sm">
    <i class="bi bi-check-circle me-1"></i>No hay parejas sospechosas pendientes.
</div>
<?php else: ?>
<div class="vstack gap-3">
    <?php foreach ($pairs as $pair): ?>
    <?php
        $a = $pair['a'];
        $b = $pair['b'];
        $label = static function (array $u): string {
            $name = trim((string) ($u['display_name'] ?: $u['username'] ?: '—'));
            $user = trim((string) ($u['username'] ?? ''));
            $bits = ['#' . (int) ($u['id'] ?? 0), $name];
            if ($user !== '' && strcasecmp($user, $name) !== 0) {
                $bits[] = '@' . $user;
            }
            return implode(' · ', $bits);
        };
        $meta = static function (array $u): string {
            $bits = [];
            $bits[] = (string) ($u['status'] ?? '—');
            $bits[] = !empty($u['email']) ? (string) $u['email'] : 'sin email';
            $bits[] = !empty($u['expires_at']) ? ('hasta ' . substr((string) $u['expires_at'], 0, 10)) : 'sin fecha';
            if (!empty($u['server_name'])) {
                $bits[] = (string) $u['server_name'];
            }
            if (!empty($u['telegram_chat_id'])) {
                $bits[] = 'TG ' . (string) $u['telegram_chat_id'];
            }
            return implode(' · ', $bits);
        };
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <span class="badge text-bg-warning"><?= e((string) ($pair['reason'] ?? 'Posible duplicado')) ?></span>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <div class="fw-semibold"><?= e($label($a)) ?></div>
                        <div class="small text-muted mb-2"><?= e($meta($a)) ?></div>
                        <a href="/media-users/<?= e((string) $a['uuid']) ?>" class="btn btn-sm btn-outline-primary">Ver ficha A</a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <div class="fw-semibold"><?= e($label($b)) ?></div>
                        <div class="small text-muted mb-2"><?= e($meta($b)) ?></div>
                        <a href="/media-users/<?= e((string) $b['uuid']) ?>" class="btn btn-sm btn-outline-primary">Ver ficha B</a>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <form method="post" action="/media-users/duplicates/merge" class="d-inline"
                      onsubmit="return confirm('¿Fusionar conservando la ficha A (#<?= (int) $a['id'] ?>) y archivando B?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="keep_id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="remove_id" value="<?= (int) $b['id'] ?>">
                    <button class="btn btn-sm btn-success">Fusionar → conservar A</button>
                </form>
                <form method="post" action="/media-users/duplicates/merge" class="d-inline"
                      onsubmit="return confirm('¿Fusionar conservando la ficha B (#<?= (int) $b['id'] ?>) y archivando A?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="keep_id" value="<?= (int) $b['id'] ?>">
                    <input type="hidden" name="remove_id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-sm btn-outline-success">Fusionar → conservar B</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
