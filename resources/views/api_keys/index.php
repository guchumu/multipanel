<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">API Keys</h4>
</div>

<?php if (!empty($newKey)): ?>
<div class="alert alert-warning">
    <strong>Nueva API key (copiar ahora):</strong>
    <code class="user-select-all d-block mt-2 p-2 bg-light"><?= e($newKey) ?></code>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/api-keys">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label">Nombre</label><input name="name" class="form-control" placeholder="Integración n8n" required></div>
                    <button class="btn btn-primary">Generar key</button>
                </form>
            </div>
        </div>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body small text-muted">
                <p class="mb-1">Header: <code>X-API-Key: mp_...</code></p>
                <p class="mb-0">Webhooks entrantes: <code>POST /api/v1/hooks/{event}</code></p>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Nombre</th><th>Prefijo</th><th>Último uso</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($keys)): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">Sin API keys</td></tr>
                    <?php else: ?>
                    <?php foreach ($keys as $k): ?>
                    <tr>
                        <td><?= e($k['name']) ?></td>
                        <td><code><?= e($k['key_prefix']) ?>...</code></td>
                        <td class="small"><?= e($k['last_used_at'] ?? 'Nunca') ?></td>
                        <td>
                            <?php if ($k['is_active']): ?>
                            <form method="POST" action="/api-keys/<?= (int) $k['id'] ?>" class="d-inline" onsubmit="return confirm('¿Revocar?')">
                                <?= csrf_field() ?><input type="hidden" name="_method" value="DELETE">
                                <button class="btn btn-sm btn-outline-danger">Revocar</button>
                            </form>
                            <?php else: ?><span class="badge bg-secondary">Revocada</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
