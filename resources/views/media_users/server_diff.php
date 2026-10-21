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
$fmtDate = static function (?string $raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '—';
    }

    return substr($raw, 0, 10);
};
$dash = static function (?string $v): string {
    $v = trim((string) $v);

    return $v !== '' ? e($v) : '<span class="text-muted">—</span>';
};
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">Inventario Plex ↔ panel</h4>
        <p class="text-muted small mb-0">
            Lee shares reales de plex.tv (token del servidor) y las fichas de la BD del panel.
            Columnas: email, caducidad y Telegram del panel junto a cada share de Plex.
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
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Actualizar ahora</button>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">En Plex (shares)</div>
            <div class="fs-3 fw-semibold"><?= (int) ($totals['plex'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">En panel (fichas)</div>
            <div class="fs-3 fw-semibold"><?= (int) ($totals['panel'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Coinciden</div>
            <div class="fs-3 fw-semibold text-success"><?= (int) ($totals['matched'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Solo Plex</div>
            <div class="fs-3 fw-semibold text-danger"><?= (int) ($totals['only_on_plex'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted small">Solo panel</div>
            <div class="fs-3 fw-semibold text-warning"><?= (int) ($totals['only_on_panel'] ?? 0) ?></div>
        </div></div>
    </div>
</div>

<?php foreach (($diff['servers'] ?? []) as $block): ?>
<?php
    $sid = (int) ($block['server_id'] ?? 0);
    $plexN = (int) ($block['plex_total'] ?? 0);
    $panelN = (int) ($block['panel_total'] ?? 0);
    $matchN = (int) ($block['matched'] ?? 0);
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <strong><?= e((string) ($block['server_name'] ?? '')) ?></strong>
        <?php if (empty($block['ok'])): ?>
        <span class="badge bg-danger">Error</span>
        <?php else: ?>
        <span class="small text-muted">Plex <?= $plexN ?> · Panel <?= $panelN ?> · Match <?= $matchN ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($block['ok'])): ?>
        <div class="alert alert-danger mb-0"><?= e((string) ($block['error'] ?? 'Error desconocido')) ?></div>
        <?php else: ?>

        <h6 class="mb-2"><i class="bi bi-display me-1"></i>Listado Plex (<?= $plexN ?>) — con datos del panel si hay match</h6>
        <div class="table-responsive mb-4" style="max-height: 28rem; overflow: auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Email (Plex)</th>
                        <th>Usuario Plex</th>
                        <th>¿En panel?</th>
                        <th>Email panel</th>
                        <th>Caducidad panel</th>
                        <th>Telegram panel</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (($block['plex_rows'] ?? []) === []): ?>
                    <tr><td colspan="8" class="text-muted text-center py-3">Ningún share en este servidor</td></tr>
                <?php else: ?>
                <?php foreach ($block['plex_rows'] as $row): ?>
                    <tr class="<?= empty($row['in_panel']) ? 'table-danger' : '' ?>">
                        <td><?= $dash($row['plex_email'] ?? '') ?></td>
                        <td><?= $dash($row['plex_username'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($row['in_panel'])): ?>
                            <span class="badge bg-success">Sí</span>
                            <?php else: ?>
                            <span class="badge bg-danger">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($row['in_panel']) ? $dash($row['email'] ?? '') : '—' ?></td>
                        <td class="text-nowrap"><?= !empty($row['in_panel']) ? e($fmtDate($row['expires_at'] ?? null)) : '—' ?></td>
                        <td class="font-monospace small"><?= !empty($row['in_panel']) ? $dash($row['telegram_chat_id'] ?? '') : '—' ?></td>
                        <td>
                            <?php if (!empty($row['in_panel']) && ($row['status'] ?? '') !== ''): ?>
                            <span class="badge <?= e($statusBadge((string) $row['status'])) ?>"><?= e((string) $row['status']) ?></span>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['uuid'])): ?>
                            <a class="btn btn-outline-secondary btn-sm py-0" href="/media-users/<?= e((string) $row['uuid']) ?>">Ver</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h6 class="mb-2"><i class="bi bi-people me-1"></i>Listado panel (<?= $panelN ?>) — ¿tiene share en Plex?</h6>
        <div class="table-responsive" style="max-height: 28rem; overflow: auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Email</th>
                        <th>Usuario</th>
                        <th>Caducidad</th>
                        <th>Telegram</th>
                        <th>Estado</th>
                        <th>¿En Plex?</th>
                        <th>on_server</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (($block['panel_rows'] ?? []) === []): ?>
                    <tr><td colspan="8" class="text-muted text-center py-3">Ninguna ficha en este servidor</td></tr>
                <?php else: ?>
                <?php foreach ($block['panel_rows'] as $row): ?>
                    <tr class="<?= empty($row['in_plex']) ? 'table-warning' : '' ?>">
                        <td><?= $dash($row['email'] ?? '') ?></td>
                        <td><?= $dash($row['username'] ?? '') ?></td>
                        <td class="text-nowrap"><?= e($fmtDate($row['expires_at'] ?? null)) ?></td>
                        <td class="font-monospace small"><?= $dash($row['telegram_chat_id'] ?? '') ?></td>
                        <td><span class="badge <?= e($statusBadge((string) ($row['status'] ?? ''))) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                        <td>
                            <?php if (!empty($row['in_plex'])): ?>
                            <span class="badge bg-success">Sí</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">No</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php
                            $os = $row['on_server'] ?? null;
                            echo $os === 1 ? '1' : ($os === 0 ? '0' : '—');
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($row['uuid'])): ?>
                            <a class="btn btn-outline-secondary btn-sm py-0" href="/media-users/<?= e((string) $row['uuid']) ?>">Ver</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
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
