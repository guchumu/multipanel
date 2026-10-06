<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Últimos añadidos</h4>
        <p class="text-muted small mb-0">Altas recientes en el panel (registro, sync o creación manual)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/media-users/duplicates" class="btn btn-outline-warning btn-sm"><i class="bi bi-intersect me-1"></i>Duplicados</a>
        <a href="/media-users" class="btn btn-outline-secondary btn-sm">Todos</a>
    </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label small mb-0">Servidor</label>
        <select name="server_id" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach ($servers as $server): ?>
            <option value="<?= (int) $server->id ?>" <?= (int) ($currentServerId ?? 0) === (int) $server->id ? 'selected' : '' ?>>
                <?= e($server->name) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">Mostrar</label>
        <select name="limit" class="form-select form-select-sm">
            <?php foreach ([30, 50, 100] as $n): ?>
            <option value="<?= $n ?>" <?= (int) ($limit ?? 50) === $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-primary">Filtrar</button>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Alta</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Servidor</th>
                    <th>Estado</th>
                    <th>Caduca</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Sin altas recientes</td></tr>
                <?php else: ?>
                <?php foreach ($users as $user): ?>
                <?php
                    $name = trim((string) ($user->display_name ?: $user->username ?: '—'));
                    $uname = trim((string) ($user->username ?? ''));
                    $created = (string) ($user->created_at ?? '');
                    $expires = $user->expires_at ? substr((string) $user->expires_at, 0, 10) : '—';
                ?>
                <tr>
                    <td class="small text-nowrap"><?= e($created !== '' ? substr($created, 0, 16) : '—') ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($name) ?></div>
                        <?php if ($uname !== '' && strcasecmp($uname, $name) !== 0): ?>
                        <div class="small text-muted">@<?= e($uname) ?></div>
                        <?php elseif ($uname === ''): ?>
                        <div class="small text-warning">sin username</div>
                        <?php endif; ?>
                        <div class="small text-muted">#<?= (int) $user->id ?></div>
                    </td>
                    <td class="small"><?= e((string) ($user->email ?: '—')) ?></td>
                    <td class="small"><?= e((string) ($user->server_name ?? '—')) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= e((string) $user->status) ?></span></td>
                    <td class="small text-nowrap"><?= e($expires) ?></td>
                    <td><a href="/media-users/<?= e($user->uuid) ?>" class="btn btn-sm btn-outline-primary">Ver</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
