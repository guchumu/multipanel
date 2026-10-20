<?php
/** @var array{servers: list<array<string, mixed>>, totals: array<string, int>} $diff */
/** @var list<\App\Models\Server> $servers */
$currentServerId = $currentServerId ?? null;
$totals = $diff['totals'] ?? [];
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">Plex vs panel</h4>
        <p class="text-muted small mb-0">
            Compara los shares reales de plex.tv (<code>shared_servers</code>) con las fichas del panel.
            Prioridad de match: email → external_id → username.
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

<div class="row g-3 mb-4">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">En Plex</div>
            <div class="fs-4 fw-semibold"><?= (int) ($totals['plex'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">En panel</div>
            <div class="fs-4 fw-semibold"><?= (int) ($totals['panel'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Coinciden</div>
            <div class="fs-4 fw-semibold text-success"><?= (int) ($totals['matched'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Solo en Plex</div>
            <div class="fs-4 fw-semibold text-danger"><?= (int) ($totals['only_on_plex'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Solo en panel</div>
            <div class="fs-4 fw-semibold text-warning"><?= (int) ($totals['only_on_panel'] ?? 0) ?></div>
        </div></div>
    </div>
</div>

<?php foreach (($diff['servers'] ?? []) as $block): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <strong><?= e((string) ($block['server_name'] ?? '')) ?></strong>
        <?php if (empty($block['ok'])): ?>
        <span class="badge bg-danger">Error</span>
        <?php else: ?>
        <span class="small text-muted">
            Plex <?= (int) ($block['plex_total'] ?? 0) ?> · Panel <?= (int) ($block['panel_total'] ?? 0) ?> ·
            Match <?= (int) ($block['matched'] ?? 0) ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($block['ok'])): ?>
        <div class="alert alert-danger mb-0"><?= e((string) ($block['error'] ?? 'Error desconocido')) ?></div>
        <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-6">
                <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Solo en Plex (<?= count($block['only_on_plex'] ?? []) ?>)</h6>
                <p class="small text-muted">Están compartidos en el servidor y no hay ficha en el panel (o no matchea por email).</p>
                <?php if (($block['only_on_plex'] ?? []) === []): ?>
                <p class="small text-success mb-0">Ninguno.</p>
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
                <h6 class="text-warning"><i class="bi bi-person-x me-1"></i>Solo en panel (<?= count($block['only_on_panel'] ?? []) ?>)</h6>
                <p class="small text-muted">Ficha en el panel sin share coincidente en plex.tv (invitado pendiente, caducado sin cortar, o email distinto).</p>
                <?php if (($block['only_on_panel'] ?? []) === []): ?>
                <p class="small text-success mb-0">Ninguno.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Email</th><th>Usuario</th><th>Estado</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($block['only_on_panel'] as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['email'] ?? '')) ?></td>
                                <td><?= e((string) ($row['username'] ?? '')) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e((string) ($row['status'] ?? '')) ?></span></td>
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
