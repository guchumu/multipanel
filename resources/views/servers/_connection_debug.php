<?php
/** @var array<string, mixed>|null $debug */
if (empty($debug)) {
    return;
}
?>
<div class="card border-0 shadow-sm border-start border-4 border-<?= !empty($debug['connected']) ? 'success' : 'danger' ?> mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-bug me-1"></i>Debug de conexión</h6>
        <span class="badge bg-<?= !empty($debug['connected']) ? 'success' : 'danger' ?>">
            <?= !empty($debug['connected']) ? 'Conectado' : 'Fallo' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (!empty($debug['lightweight'])): ?>
        <div class="alert alert-secondary py-2 small mb-3">
            Resumen del último intento. Pulsa el botón <strong>Debug</strong> para analizar todas las URLs.
        </div>
        <?php endif; ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <dl class="mb-0 small">
                    <dt class="text-muted">URL configurada</dt>
                    <dd><code><?= e($debug['configured_url'] ?? '-') ?></code></dd>
                    <dt class="text-muted">Machine ID</dt>
                    <dd class="text-break"><?= e($debug['machine_id'] ?: '—') ?></dd>
                    <dt class="text-muted">Token</dt>
                    <dd><?= !empty($debug['has_token']) ? '✓ Presente' : '✗ Falta' ?></dd>
                </dl>
            </div>
            <div class="col-md-6">
                <dl class="mb-0 small">
                    <dt class="text-muted">Comprobado</dt>
                    <dd><?= e($debug['checked_at'] ?? '-') ?></dd>
                    <?php if (!empty($debug['working_endpoint'])): ?>
                    <dt class="text-muted">Endpoint OK</dt>
                    <dd><code class="text-success"><?= e($debug['working_endpoint']) ?></code></dd>
                    <?php endif; ?>
                    <?php if (!empty($debug['final_error'])): ?>
                    <dt class="text-muted">Error</dt>
                    <dd class="text-danger"><?= e($debug['final_error']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (!empty($debug['suggestions'])): ?>
        <div class="alert alert-info py-2 small mb-3">
            <strong>Sugerencias:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($debug['suggestions'] as $tip): ?>
                <li><?= e($tip) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($debug['probes'])): ?>
        <h6 class="small text-muted text-uppercase">URLs probadas</h6>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>URL</th><th>Estado</th><th>Latencia</th><th>Error</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($debug['probes'] as $probe): ?>
                    <tr class="<?= !empty($probe['ok']) ? 'table-success' : '' ?>">
                        <td class="small"><code><?= e($probe['url']) ?></code>
                            <?php if (!empty($probe['local'])): ?><span class="badge bg-warning text-dark ms-1">local</span><?php endif; ?>
                        </td>
                        <td><?= !empty($probe['ok']) ? '✓ OK' : '✗ Fail' ?></td>
                        <td><?= isset($probe['latency_ms']) ? (int) $probe['latency_ms'] . ' ms' : '-' ?></td>
                        <td class="small text-danger"><?= e($probe['error'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($debug['plex_tv']['servers'])): ?>
        <h6 class="small text-muted text-uppercase">Recursos plex.tv (<?= (int) ($debug['plex_tv']['resources_found'] ?? 0) ?>)</h6>
        <?php foreach ($debug['plex_tv']['servers'] as $plexServer): ?>
        <div class="border rounded p-2 mb-2 small">
            <strong><?= e($plexServer['name']) ?></strong>
            <?php if (!empty($plexServer['matches_machine_id'])): ?><span class="badge bg-primary ms-1">machine_id match</span><?php endif; ?>
            <div class="text-muted">ID: <code><?= e($plexServer['client_id']) ?></code></div>
            <?php if (!empty($plexServer['connections'])): ?>
            <ul class="mb-0 mt-1">
                <?php foreach ($plexServer['connections'] as $conn): ?>
                <li><code><?= e($conn['url']) ?></code>
                    <?= !empty($conn['local']) ? '(local)' : '' ?>
                    <?= !empty($conn['relay']) ? '(relay)' : '' ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php elseif (!empty($debug['plex_tv']['error'])): ?>
        <div class="alert alert-warning small mb-0">plex.tv: <?= e($debug['plex_tv']['error']) ?></div>
        <?php endif; ?>
    </div>
</div>
