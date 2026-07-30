<?php ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Webhooks salientes</h4>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Nuevo endpoint</strong></div>
            <div class="card-body">
                <form method="POST" action="/webhooks">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label">Nombre</label><input name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">URL</label><input name="url" type="url" class="form-control" placeholder="https://..." required></div>
                    <div class="mb-3"><label class="form-label">Secret (opcional)</label><input name="secret" class="form-control"></div>
                    <div class="mb-3">
                        <label class="form-label">Eventos</label>
                        <?php foreach ($events as $event): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="events[]" value="<?= e($event) ?>" id="ev-<?= e($event) ?>">
                            <label class="form-check-label small" for="ev-<?= e($event) ?>"><?= e($event) ?></label>
                        </div>
                        <?php endforeach; ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="*" id="ev-all"><label class="form-check-label small" for="ev-all">Todos (*)</label></div>
                    </div>
                    <button class="btn btn-primary">Crear webhook</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Endpoints activos</strong></div>
            <div class="list-group list-group-flush">
                <?php if (empty($endpoints)): ?>
                <div class="list-group-item text-muted">No hay webhooks configurados</div>
                <?php else: ?>
                <?php foreach ($endpoints as $ep): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= e($ep['name']) ?></strong>
                        <div class="small text-muted"><?= e($ep['url']) ?></div>
                    </div>
                    <div>
                        <form method="POST" action="/webhooks/<?= (int) $ep['id'] ?>/test" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Test</button></form>
                        <form method="POST" action="/webhooks/<?= (int) $ep['id'] ?>" class="d-inline" onsubmit="return confirm('¿Eliminar?')"><?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-outline-danger">×</button></form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($deliveries)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Últimas entregas</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Evento</th><th>Endpoint</th><th>HTTP</th><th>Fecha</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td><code><?= e($d['event']) ?></code></td>
                        <td><?= e($d['endpoint_name']) ?></td>
                        <td><span class="badge bg-<?= ($d['response_code'] ?? 0) >= 200 && ($d['response_code'] ?? 0) < 300 ? 'success' : 'danger' ?>"><?= (int) ($d['response_code'] ?? 0) ?></span></td>
                        <td class="small"><?= e($d['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
