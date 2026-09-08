<?php
/** @var array<int, array<string, mixed>> $logs */
/** @var string $kindFilter */
/** @var \App\Services\PlaybackCutLogService $cutLogService */
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <a href="/activity" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>En directo</a>
        <h4 class="mb-0 mt-1">Log de cortes</h4>
        <p class="text-muted small mb-0">
            Qué se cortó, por qué, y el mensaje enviado al cliente.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/media-users/stream-violations" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-exclamation-octagon me-1"></i>Incumplimientos
        </a>
        <a href="/activity" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-broadcast-pin me-1"></i>En directo
        </a>
    </div>
</div>

<div class="mb-3 d-flex flex-wrap gap-2">
    <?php
    $filters = [
        '' => 'Todos',
        \App\Services\PlaybackCutLogService::KIND_VIDEO_TRANSCODE => 'Transcode',
        \App\Services\PlaybackCutLogService::KIND_STREAM_HOME => 'Casa',
        \App\Services\PlaybackCutLogService::KIND_STREAM_AWAY => 'Fuera',
        \App\Services\PlaybackCutLogService::KIND_STREAM_GENERIC => 'Streams',
    ];
    foreach ($filters as $value => $label):
        $active = $kindFilter === $value;
        $href = '/media-users/cut-logs' . ($value !== '' ? '?kind=' . rawurlencode($value) : '');
    ?>
    <a href="<?= e($href) ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Cuándo</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Título</th>
                    <th>Por qué</th>
                    <th>Mensaje al cliente</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs === []): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">Aún no hay cortes registrados.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $row): ?>
                <?php
                    $kind = (string) ($row['kind'] ?? '');
                    $killed = !empty($row['killed']);
                ?>
                <tr>
                    <td class="small text-nowrap"><?= e((string) ($row['created_at'] ?? '')) ?></td>
                    <td>
                        <div><?= e((string) ($row['username'] ?? '—')) ?></div>
                        <div class="small text-muted"><?= e((string) ($row['server_name'] ?? '—')) ?></div>
                    </td>
                    <td>
                        <span class="badge <?= $kind === \App\Services\PlaybackCutLogService::KIND_VIDEO_TRANSCODE ? 'text-bg-warning' : 'text-bg-secondary' ?>">
                            <?= e($cutLogService->kindLabel($kind)) ?>
                        </span>
                        <?php if (!$killed): ?>
                        <div class="small text-danger mt-1">No cortó</div>
                        <?php endif; ?>
                    </td>
                    <td class="small" style="max-width: 220px;"><?= e((string) ($row['title'] ?? '—')) ?></td>
                    <td class="small" style="max-width: 260px;"><?= e((string) ($row['cut_why'] ?? '')) ?></td>
                    <td class="small text-muted" style="max-width: 320px;"><?= e((string) ($row['client_message'] ?? '—')) ?></td>
                    <td>
                        <?php if (!empty($row['media_user_uuid'])): ?>
                        <a href="/media-users/<?= e((string) $row['media_user_uuid']) ?>" class="btn btn-sm btn-outline-primary">Ver</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
