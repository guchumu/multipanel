<?php
/** @var array{servers: list<array<string, mixed>>, totals: array<string, int>} $diff */
/** @var list<\App\Models\Server> $servers */
$currentServerId = $currentServerId ?? null;
$totals = $diff['totals'] ?? [];
$statusBadge = static function (string $status): string {
    return match ($status) {
        'active' => 'bg-success',
        'invited' => 'bg-info text-dark',
        'suspended' => 'bg-warning text-dark',
        'expired' => 'bg-secondary',
        default => 'bg-light text-dark border',
    };
};
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">Plex vs panel</h4>
        <p class="text-muted small mb-0">
            Compara shares reales de plex.tv con fichas del panel.
            Match por email → external_id → username.
        </p>
    </div>
    <a href="/media-users" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
</div>

<form method="GET" action="/media-users/plex-vs-panel" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <label class="small text-muted mb-0" for="server_id">Servidor</label>
        <select name="server_id" id="server_id" class="form-select form-select-sm" style="width: auto; min-width: 12rem">
            <option value="">Todos los Plex</option>
            <?php foreach ($servers as $server): ?>
            <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>>
                <?= e((string) $server->name) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Comparar ahora</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Shares en Plex</div>
            <div class="fs-4 fw-semibold"><?= (int) ($totals['plex'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Fichas en panel</div>
            <div class="fs-4 fw-semibold"><?= (int) ($totals['panel'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100 border-success border-opacity-25"><div class="card-body py-3">
            <div class="text-muted small">En ambos (OK)</div>
            <div class="fs-4 fw-semibold text-success"><?= (int) ($totals['matched'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Huérfanos en Plex</div>
            <div class="fs-4 fw-semibold text-danger"><?= (int) ($totals['only_on_plex'] ?? 0) ?></div>
            <div class="small text-muted">share sin ficha</div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Sobrantes en panel</div>
            <div class="fs-4 fw-semibold text-warning"><?= (int) ($totals['only_on_panel'] ?? 0) ?></div>
            <div class="small text-muted">ficha sin share</div>
        </div></div>
    </div>
</div>

<?php foreach (($diff['servers'] ?? []) as $block): ?>
<?php
    $plexN = (int) ($block['plex_total'] ?? 0);
    $matchN = (int) ($block['matched'] ?? 0);
    $orphanPlex = count($block['only_on_plex'] ?? []);
    $orphanPanel = count($block['only_on_panel'] ?? []);
    $collapseId = 'matched-' . (int) ($block['server_id'] ?? 0);
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <strong><?= e((string) ($block['server_name'] ?? '')) ?></strong>
        <?php if (empty($block['ok'])): ?>
        <span class="badge bg-danger">Error</span>
        <?php else: ?>
        <span class="small text-muted">
            Plex <?= $plexN ?> · Panel <?= (int) ($block['panel_total'] ?? 0) ?> ·
            En ambos <?= $matchN ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($block['ok'])): ?>
        <div class="alert alert-danger mb-0"><?= e((string) ($block['error'] ?? 'Error desconocido')) ?></div>
        <?php else: ?>

        <?php if ($plexN > 0 && $orphanPlex === 0): ?>
        <div class="alert alert-success py-2">
            <strong>Los <?= $plexN ?> usuarios de Plex tienen ficha en el panel.</strong>
            «Huérfanos en Plex = 0» es bueno: nadie compartido se ha escapado del panel.
            <?php if ($orphanPanel > 0): ?>
            Los <?= $orphanPanel ?> de «sobrantes en panel» son fichas sin share activo (caducados, invitados, duplicados de otro servidor, etc.).
            <?php endif; ?>
        </div>
        <?php elseif ($plexN === 0): ?>
        <div class="alert alert-warning py-2 mb-3">
            plex.tv no devolvió ningún share para este servidor (¿machine_id/token?).
        </div>
        <?php endif; ?>

        <div class="mb-4">
            <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>" aria-expanded="false">
                <i class="bi bi-check2-circle me-1"></i>Ver los <?= $matchN ?> que están en ambos
            </button>
            <div class="collapse mt-2" id="<?= e($collapseId) ?>">
                <?php if (($block['matched_rows'] ?? []) === []): ?>
                <p class="small text-muted mb-0">Ninguno emparejado.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Email</th><th>Usuario</th><th>Estado</th><th>Match</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($block['matched_rows'] as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['email'] ?? '')) ?></td>
                                <td><?= e((string) ($row['username'] ?? '')) ?></td>
                                <td><span class="badge <?= e($statusBadge((string) ($row['status'] ?? ''))) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                                <td class="small text-muted"><?= e((string) ($row['match_by'] ?? '')) ?></td>
                                <td>
                                    <?php if (!empty($row['uuid'])): ?>
                                    <a class="btn btn-outline-secondary btn-sm py-0" href="/media-users/<?= e((string) $row['uuid']) ?>">Ver</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Huérfanos en Plex (<?= $orphanPlex ?>)</h6>
                <p class="small text-muted">Share en plex.tv <em>sin</em> ficha en el panel. Si es 0, perfecto.</p>
                <?php if ($orphanPlex === 0): ?>
                <p class="small text-success mb-0">Ninguno — nadie se escapó del panel.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Email</th><th>Usuario</th><th>Plex ID</th></tr></thead>
                        <tbody>
                        <?php foreach ($block['only_on_plex'] as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['email'] ?? '')) ?></td>
                                <td><?= e((string) ($row['username'] ?? '')) ?></td>
                                <td class="small text-muted"><?= e((string) ($row['user_id'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-6">
                <h6 class="text-warning"><i class="bi bi-person-x me-1"></i>Sobrantes en panel (<?= $orphanPanel ?>)</h6>
                <p class="small text-muted">Ficha sin share en plex.tv (invitado, caducado, email distinto, o duplicado de otro servidor).</p>
                <?php if ($orphanPanel === 0): ?>
                <p class="small text-success mb-0">Ninguno.</p>
                <?php else: ?>
                <div class="table-responsive" style="max-height: 28rem; overflow: auto">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Email</th><th>Usuario</th><th>Estado</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($block['only_on_panel'] as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['email'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= e((string) ($row['username'] ?? '')) ?></td>
                                <td><span class="badge <?= e($statusBadge((string) ($row['status'] ?? ''))) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                                <td>
                                    <?php if (!empty($row['uuid'])): ?>
                                    <a class="btn btn-outline-secondary btn-sm py-0" href="/media-users/<?= e((string) $row['uuid']) ?>">Ver</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (($diff['servers'] ?? []) === []): ?>
<div class="alert alert-secondary">No hay servidores Plex configurados.</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require base_path('resources/views/layouts/app.php');
