<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Próximos vencimientos</h4>
        <p class="text-muted small mb-0">Usuarios a los que se les acaba la suscripción, servidor y días que les quedan</p>
    </div>
    <a href="/media-users/broadcast" class="btn btn-outline-info btn-sm"><i class="bi bi-megaphone me-1"></i>Mensaje masivo</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/media-users/expiring" class="d-flex flex-wrap gap-3 align-items-center">
            <div class="btn-group btn-group-sm">
                <?php foreach ([3, 7, 15, 30, 60] as $opt): ?>
                <a href="?days=<?= $opt ?><?= $currentServerId ? '&server_id=' . (int) $currentServerId : '' ?>"
                   class="btn btn-outline-warning <?= $currentDays === $opt ? 'active' : '' ?>"><?= $opt ?> días</a>
                <?php endforeach; ?>
            </div>
            <label class="small text-muted mb-0">Servidor:</label>
            <select name="server_id" class="form-select form-select-sm" style="min-width: 180px;" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($servers as $server): ?>
                <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>><?= e($server->name) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="days" value="<?= (int) $currentDays ?>">
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="px-3 py-2 border-bottom bg-light small">
        <strong><?= count($users) ?></strong> usuario(s) vencen en los próximos <strong><?= (int) $currentDays ?></strong> días (o ya caducados)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Servidor</th>
                    <th>Estado</th>
                    <th>Fecha expiración</th>
                    <th>Días restantes</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No hay vencimientos próximos en este rango 🎉</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <?php $dl = days_left_badge($u->expires_at); ?>
                <tr>
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
                        <span class="badge <?= $u->status === 'active' ? 'bg-success' : ($u->status === 'suspended' ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                            <?= e($u->status) ?>
                        </span>
                    </td>
                    <td class="small"><?= e($u->expires_at ? substr((string) $u->expires_at, 0, 10) : '-') ?></td>
                    <td><span class="badge <?= e($dl['class']) ?>"><?= e($dl['label']) ?></span></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="/media-users/<?= e($u->uuid) ?>" class="btn btn-outline-primary" title="Ver ficha"><i class="bi bi-eye"></i></a>
                            <?php if ($u->telegram_chat_id): ?>
                            <a href="/media-users/<?= e($u->uuid) ?>" class="btn btn-outline-info" title="Tiene Telegram configurado"><i class="bi bi-send"></i></a>
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
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
