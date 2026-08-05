<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="min-w-0">
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1 text-truncate">Próximos vencimientos</h4>
        <p class="text-muted small mb-0">Selecciona usuarios y envía un aviso Telegram, o renueva días</p>
    </div>
    <a href="/media-users/broadcast" class="btn btn-outline-info btn-sm flex-shrink-0"><i class="bi bi-megaphone me-1"></i>Mensaje masivo</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/media-users/expiring" class="d-flex flex-wrap gap-2 gap-md-3 align-items-center">
            <div class="btn-group btn-group-sm flex-wrap">
                <?php foreach ([3, 7, 15, 30, 60] as $opt): ?>
                <a href="?days=<?= $opt ?><?= $currentServerId ? '&server_id=' . (int) $currentServerId : '' ?>"
                   class="btn btn-outline-warning <?= $currentDays === $opt ? 'active' : '' ?>"><?= $opt ?>d</a>
                <?php endforeach; ?>
            </div>
            <label class="small text-muted mb-0">Servidor:</label>
            <select name="server_id" class="form-select form-select-sm" style="min-width: 140px; max-width: 220px;" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($servers as $server): ?>
                <option value="<?= (int) $server->id ?>" <?= $currentServerId === (int) $server->id ? 'selected' : '' ?>><?= e($server->name) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="days" value="<?= (int) $currentDays ?>">
        </form>
    </div>
</div>

<div id="bulkMessageBar" class="card border-0 shadow-sm mb-3 d-none">
    <div class="card-body py-3">
        <form method="POST" action="/media-users/expiring/broadcast" id="bulkMessageForm">
            <?= csrf_field() ?>
            <input type="hidden" name="days" value="<?= (int) $currentDays ?>">
            <?php if ($currentServerId): ?>
            <input type="hidden" name="server_id" value="<?= (int) $currentServerId ?>">
            <?php endif; ?>
            <div id="bulkUuidInputs"></div>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <strong class="small"><span id="bulkSelectedCount">0</span> seleccionado(s)</strong>
                <button type="button" class="btn btn-link btn-sm p-0" id="bulkClearSelection">Limpiar selección</button>
            </div>
            <div class="row g-2">
                <div class="col-12 col-md-3">
                    <input type="text" name="title" class="form-control form-control-sm" value="Aviso de vencimiento" required placeholder="Título">
                </div>
                <div class="col-12 col-md-7">
                    <textarea name="body" class="form-control form-control-sm" rows="2" required placeholder="Hola {display_name}, tu acceso vence el {end_date}…">{variables: {username}, {email}, {display_name}, {end_date}, {days_left}, {server_name}</textarea>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-info btn-sm" onclick="return confirm('¿Enviar Telegram a los usuarios seleccionados?')">
                        <i class="bi bi-send me-1"></i>Enviar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm expiring-card">
    <div class="px-3 py-2 border-bottom bg-light small d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>
            <strong><?= count($users) ?></strong> usuario(s) en los próximos <strong><?= (int) $currentDays ?></strong> días (o ya caducados)
        </span>
        <label class="mb-0 d-inline-flex align-items-center gap-1">
            <input type="checkbox" class="form-check-input m-0" id="selectAllExpiring">
            <span>Seleccionar todos</span>
        </label>
    </div>
    <div class="table-responsive expiring-table-wrap">
        <table class="table table-hover mb-0 align-middle expiring-table">
            <thead class="table-light">
                <tr>
                    <th style="width: 2.5rem;"></th>
                    <th>Usuario</th>
                    <th class="d-none d-md-table-cell">Email</th>
                    <th>Servidor</th>
                    <th class="d-none d-lg-table-cell">Estado</th>
                    <th>Vence</th>
                    <th>Días</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No hay vencimientos próximos en este rango</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <?php $dl = days_left_badge($u->expires_at); ?>
                <tr>
                    <td>
                        <input type="checkbox" class="form-check-input expiring-select"
                               value="<?= e($u->uuid) ?>"
                               data-has-telegram="<?= $u->telegram_chat_id ? '1' : '0' ?>"
                               aria-label="Seleccionar <?= e($u->display_name ?? $u->username) ?>">
                    </td>
                    <td class="min-w-0">
                        <a href="/media-users/<?= e($u->uuid) ?>" class="fw-medium text-decoration-none text-truncate d-inline-block" style="max-width: 12rem;">
                            <?= e($u->display_name ?? $u->username) ?>
                        </a>
                        <div class="small text-muted d-md-none text-truncate" style="max-width: 12rem;"><?= e($u->email ?? '-') ?></div>
                    </td>
                    <td class="small d-none d-md-table-cell text-truncate" style="max-width: 10rem;"><?= e($u->email ?? '-') ?></td>
                    <td class="small">
                        <?php if ($u->server_name): ?>
                        <span class="badge bg-light text-dark border text-truncate d-inline-block" style="max-width: 8rem;"><?= e($u->server_name) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <span class="badge <?= $u->status === 'active' ? 'bg-success' : ($u->status === 'suspended' ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                            <?= e($u->status) ?>
                        </span>
                    </td>
                    <td class="small text-nowrap"><?= e($u->expires_at ? substr((string) $u->expires_at, 0, 10) : '-') ?></td>
                    <td><span class="badge <?= e($dl['class']) ?>"><?= e($dl['label']) ?></span></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm flex-wrap justify-content-end">
                            <a href="/media-users/<?= e($u->uuid) ?>" class="btn btn-outline-primary" title="Ver ficha"><i class="bi bi-eye"></i></a>
                            <a href="/media-users/<?= e($u->uuid) ?>#stripe" class="btn btn-outline-warning" title="Enlace de pago"><i class="bi bi-credit-card"></i></a>
                            <button type="button" class="btn btn-outline-success btn-quick-renew" data-uuid="<?= e($u->uuid) ?>" data-days="30" title="Sumar 30 días">
                                <i class="bi bi-calendar-plus"></i><span class="d-none d-xl-inline"> +30d</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" title="Otras cantidades">
                                <span class="visually-hidden">Otras cantidades</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php foreach ([7, 15, 30, 90, 365] as $opt): ?>
                                <li><a class="dropdown-item btn-quick-renew" href="#" data-uuid="<?= e($u->uuid) ?>" data-days="<?= $opt ?>">+<?= $opt ?> días</a></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($u->telegram_chat_id): ?>
                            <span class="btn btn-outline-info disabled" title="Tiene Telegram"><i class="bi bi-telegram"></i></span>
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
$scripts = '<script src="' . e(asset('js/media-users-expiring.js')) . '"></script>';
include base_path('resources/views/layouts/app.php');
?>
