<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Automatizaciones</h4>
    <div>
        <button class="btn btn-outline-primary me-2" id="btnRunAll"><i class="bi bi-play-fill me-1"></i>Ejecutar ahora</button>
        <a href="/automation/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva regla</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6>Reglas predefinidas del sistema</h6>
        <ul class="small text-muted mb-0">
            <li>Si no paga en <strong>5 días</strong> → Suspender usuario</li>
            <li>Si suspendido <strong>15 días</strong> → Eliminar usuario</li>
            <li>Si vuelve a pagar → Reactivar automáticamente</li>
            <li>Usuarios con fecha de expiración pasada → Marcar como expirados</li>
            <li>Servidor offline → Notificar por Telegram/Discord</li>
        </ul>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Trigger</th>
                    <th>Prioridad</th>
                    <th>Ejecuciones</th>
                    <th>Última ejecución</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rules)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No hay reglas configuradas</td></tr>
                <?php else: ?>
                <?php foreach ($rules as $rule): ?>
                <tr>
                    <td>
                        <strong><?= e($rule['name']) ?></strong>
                        <?php if ($rule['description']): ?>
                        <br><small class="text-muted"><?= e($rule['description']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e($rule['trigger_event']) ?></code></td>
                    <td><?= (int) $rule['priority'] ?></td>
                    <td><?= (int) $rule['run_count'] ?></td>
                    <td class="small"><?= e($rule['last_run_at'] ?? 'Nunca') ?></td>
                    <td>
                        <span class="badge bg-<?= $rule['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $rule['is_active'] ? 'Activa' : 'Inactiva' ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary btn-toggle" data-id="<?= (int) $rule['id'] ?>">
                            <i class="bi bi-toggle-<?= $rule['is_active'] ? 'on' : 'off' ?>"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'JS'
<script>
document.getElementById('btnRunAll')?.addEventListener('click', async () => {
    const res = await fetch('/automation/run', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    const data = await res.json();
    alert(data.message);
    location.reload();
});
document.querySelectorAll('.btn-toggle').forEach(btn => {
    btn.addEventListener('click', async function() {
        await fetch(`/automation/${this.dataset.id}/toggle`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
        location.reload();
    });
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
