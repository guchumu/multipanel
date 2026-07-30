<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Integraciones</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addIntegration"><i class="bi bi-plus-lg"></i> Añadir</button>
</div>

<div class="row g-4">
    <?php if (empty($integrations)): ?>
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-plug fs-1"></i><p class="mt-2">No hay integraciones configuradas</p>
    </div></div></div>
    <?php else: ?>
    <?php foreach ($integrations as $int): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <h6><?= e($int['name']) ?></h6>
                    <span class="badge bg-dark"><?= e(strtoupper($int['type'])) ?></span>
                </div>
                <p class="small text-muted text-truncate"><?= e($int['url']) ?></p>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary btn-test-int" data-id="<?= (int)$int['id'] ?>"><i class="bi bi-plug"></i> Test</button>
                    <button class="btn btn-outline-info btn-stats-int" data-id="<?= (int)$int['id'] ?>"><i class="bi bi-bar-chart"></i> Stats</button>
                </div>
                <div class="int-stats small mt-2 text-muted" id="stats-<?= (int)$int['id'] ?>"></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="addIntegration" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="/integrations" class="modal-content">
        <?= csrf_field() ?>
        <div class="modal-header"><h5 class="modal-title">Nueva integración</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nombre</label><input name="name" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Tipo</label>
                <select name="type" class="form-select" required>
                    <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"><?= e(ucfirst($t)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">URL</label><input name="url" class="form-control" placeholder="http://localhost:8989" required></div>
            <div class="mb-3"><label class="form-label">API Key</label><input name="api_key" class="form-control" required></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Guardar</button></div>
    </form></div>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
document.querySelectorAll('.btn-test-int').forEach(btn => {
    btn.addEventListener('click', async function() {
        const res = await fetch(`/integrations/${this.dataset.id}/test`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
        const data = await res.json();
        alert(data.connected ? 'Conexión OK' : 'Error de conexión');
    });
});
document.querySelectorAll('.btn-stats-int').forEach(btn => {
    btn.addEventListener('click', async function() {
        const res = await fetch(`/integrations/${this.dataset.id}/stats`);
        const data = await res.json();
        document.getElementById('stats-' + this.dataset.id).textContent = JSON.stringify(data.data || {});
    });
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
