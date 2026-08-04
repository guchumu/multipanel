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

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'active' => 'bg-success',
        'suspended' => 'bg-warning text-dark',
        'pending' => 'bg-secondary',
        default => 'bg-light text-dark border',
    };
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'active' => 'Activo',
        'suspended' => 'Suspendido',
        'pending' => 'Pendiente',
        'invited' => 'Invitado',
        'inactive' => 'Inactivo',
        default => ucfirst($status),
    };
};

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">Usuarios Media</h4>
    <div class="d-flex gap-2">
        <a href="/media-users/activity" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i>Actividad</a>
        <a href="/media-users/expiring" class="btn btn-outline-warning"><i class="bi bi-hourglass-split me-1"></i>Próximos vencimientos</a>
        <a href="/media-users/broadcast" class="btn btn-outline-info"><i class="bi bi-megaphone me-1"></i>Mensaje masivo</a>
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
            <form method="GET" action="/media-users" class="d-flex gap-2 align-items-center ms-auto flex-wrap">
                <div class="position-relative" style="min-width: 220px;">
                    <input type="search" id="userSearch" class="form-control form-control-sm" placeholder="Buscar usuario, email, Telegram…" autocomplete="off">
                    <div id="userSearchMeta" class="small text-muted mt-1 d-none"></div>
                </div>
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
    <div class="px-3 py-2 border-bottom bg-light small d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span id="usersCountSummary">
            Mostrando <strong><?= (int) $showingCount ?></strong> de <strong><?= (int) $totalCount ?></strong> usuarios
            <?php if ($totalCount > $perPage): ?>
            <span class="text-muted">(página <?= (int) $page ?>)</span>
            <?php endif; ?>
        </span>
        <span class="text-muted">ID = identificador interno del usuario</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 4rem;">ID</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Servidor</th>
                    <th>Estado</th>
                    <th>Streams</th>
                    <th>Expira</th>
                    <th>Vence en</th>
                    <th>Telegram</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <?php if (empty($users)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No hay usuarios</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="small text-muted"><?= (int) $u->id ?></td>
                    <td><a href="/media-users/<?= e($u->uuid) ?>" class="fw-medium text-decoration-none"><?= e($u->display_name ?? $u->username) ?></a></td>
                    <td class="small"><?= e($u->email ?? '-') ?></td>
                    <td class="small">
                        <?php if ($u->server_name): ?>
                        <span class="badge bg-light text-dark border"><?= e($u->server_name) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= e($statusBadgeClass((string) $u->status)) ?>">
                            <?= e($statusLabel((string) $u->status)) ?>
                        </span>
                    </td>
                    <td><?= (int) $u->max_streams ?></td>
                    <td class="small">
                        <input type="date" class="form-control form-control-sm expires-input" data-uuid="<?= e($u->uuid) ?>"
                               value="<?= e($u->expires_at ? substr((string) $u->expires_at, 0, 10) : '') ?>">
                    </td>
                    <td class="small text-nowrap">
                        <?php $dl = days_left_badge($u->expires_at); ?>
                        <span class="badge <?= e($dl['class']) ?>"><?= e($dl['label']) ?></span>
                    </td>
                    <td class="small" style="min-width: 120px;">
                        <input type="text" class="form-control form-control-sm telegram-input" data-uuid="<?= e($u->uuid) ?>"
                               value="<?= e($u->telegram_chat_id ?? '') ?>" placeholder="Chat ID" title="Telegram Chat ID para enviar mensajes">
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="/media-users/<?= e($u->uuid) ?>/messages" class="btn btn-outline-info" title="Historial mensajes"><i class="bi bi-chat-dots"></i></a>
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
async function toggleUserStatus(uuid, action, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    try {
        const res = await fetch(`/media-users/${uuid}/${action}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
        });
        const data = await res.json().catch(() => ({}));
        const msg = data.error || data.message || (res.ok ? 'Hecho.' : 'No se pudo completar la acción.');
        alert(msg);
        // Recargar siempre: el estado en BD puede haber cambiado aunque falle la sync del servidor.
        location.reload();
    } catch (err) {
        alert('Error de red: ' + err.message);
    }
}
function suspendUser(uuid) {
    toggleUserStatus(uuid, 'suspend', '¿Suspender este usuario? Se cortará el acceso a la biblioteca.');
}
function activateUser(uuid) {
    toggleUserStatus(uuid, 'activate');
}
</script>
JS;
$scripts .= '<script src="' . e(asset('js/media-users-search.js')) . '"></script>';
include base_path('resources/views/layouts/app.php');
