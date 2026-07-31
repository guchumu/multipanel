<?php
$queryBase = static function (?string $status, ?int $serverId) {
    $params = [];
    if ($status) {
        $params['status'] = $status;
    }
    if ($serverId) {
        $params['server_id'] = $serverId;
    }
    return $params !== [] ? '?' . http_build_query($params) : '';
};

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Usuarios Media</h4>
    <div class="d-flex gap-2">
        <a href="/media-users/bulk" class="btn btn-outline-primary"><i class="bi bi-envelope-plus me-1"></i>Añadir emails</a>
        <a href="/media-users/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo usuario</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <div class="btn-group btn-group-sm">
                <a href="/media-users<?= e($queryBase(null, $currentServerId)) ?>" class="btn btn-outline-secondary <?= !$currentStatus ? 'active' : '' ?>">Todos</a>
                <a href="/media-users<?= e($queryBase('active', $currentServerId)) ?>" class="btn btn-outline-success <?= $currentStatus === 'active' ? 'active' : '' ?>">Activos</a>
                <a href="/media-users<?= e($queryBase('suspended', $currentServerId)) ?>" class="btn btn-outline-warning <?= $currentStatus === 'suspended' ? 'active' : '' ?>">Suspendidos</a>
                <a href="/media-users<?= e($queryBase('pending', $currentServerId)) ?>" class="btn btn-outline-secondary <?= $currentStatus === 'pending' ? 'active' : '' ?>">Pendientes</a>
            </div>
            <form method="GET" action="/media-users" class="d-flex gap-2 align-items-center ms-auto">
                <?php if ($currentStatus): ?>
                <input type="hidden" name="status" value="<?= e($currentStatus) ?>">
                <?php endif; ?>
                <label class="small text-muted mb-0">Servidor:</label>
                <select name="server_id" class="form-select form-select-sm" style="min-width: 180px;" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($servers as $server): ?>
                    <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>>
                        <?= e($server->name) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Servidor</th>
                    <th>Estado</th>
                    <th>Streams</th>
                    <th>Expira</th>
                    <th>Telegram</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No hay usuarios</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u->display_name ?? $u->username) ?></td>
                    <td class="small"><?= e($u->email ?? '-') ?></td>
                    <td class="small">
                        <?php if ($u->server_name): ?>
                        <span class="badge bg-light text-dark border"><?= e($u->server_name) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= e($u->status) ?></span></td>
                    <td><?= (int) $u->max_streams ?></td>
                    <td class="small">
                        <input type="date" class="form-control form-control-sm expires-input" data-uuid="<?= e($u->uuid) ?>"
                               value="<?= e($u->expires_at ? substr((string) $u->expires_at, 0, 10) : '') ?>">
                    </td>
                    <td class="small" style="min-width: 120px;">
                        <input type="text" class="form-control form-control-sm telegram-input" data-uuid="<?= e($u->uuid) ?>"
                               value="<?= e($u->telegram_chat_id ?? '') ?>" placeholder="Chat ID">
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <?php if ($u->status === 'active'): ?>
                            <button class="btn btn-outline-warning" onclick="suspendUser('<?= e($u->uuid) ?>')"><i class="bi bi-pause"></i></button>
                            <?php else: ?>
                            <button class="btn btn-outline-success" onclick="activateUser('<?= e($u->uuid) ?>')"><i class="bi bi-play"></i></button>
                            <?php endif; ?>
                        </div>
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
async function suspendUser(uuid) {
    await fetch(`/media-users/${uuid}/suspend`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    location.reload();
}
async function activateUser(uuid) {
    await fetch(`/media-users/${uuid}/activate`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    location.reload();
}
document.querySelectorAll('.expires-input').forEach(input => {
    input.addEventListener('change', async function() {
        const res = await fetch(`/media-users/${this.dataset.uuid}/expires`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ expires_at: this.value }),
        });
        if (!res.ok) alert('Error al guardar fecha');
    });
});
document.querySelectorAll('.telegram-input').forEach(input => {
    input.addEventListener('change', async function() {
        const res = await fetch(`/media-users/${this.dataset.uuid}/telegram`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ telegram_chat_id: this.value }),
        });
        if (!res.ok) alert('Error al guardar Telegram');
    });
});
</script>
JS;
include base_path('resources/views/layouts/app.php');
