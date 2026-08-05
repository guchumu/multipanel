<?php
/** @var array<int, array<string, mixed>> $violations */
/** @var bool $enforcementEnabled */
/** @var int $defaultMaxStreams */
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <a href="/media-users" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
        <h4 class="mb-0 mt-1">Incumplimientos de streams</h4>
        <p class="text-muted small mb-0">
            Quién superó el límite de reproducciones simultáneas y qué se cortó.
            Límite por defecto del tenant: <?= (int) $defaultMaxStreams ?> ·
            Aplicación automática: <?= !empty($enforcementEnabled) ? 'activa' : 'desactivada' ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/settings/stream-limits" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-sliders me-1"></i>Configurar límite
        </a>
        <a href="/activity" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-broadcast-pin me-1"></i>En directo
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Servidor</th>
                    <th>Streams</th>
                    <th>Límite</th>
                    <th>Títulos / acción</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($violations)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        Sin incumplimientos registrados todavía.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($violations as $v): ?>
                <?php
                    $titles = is_array($v['titles'] ?? null) ? $v['titles'] : [];
                    $titleBits = [];
                    foreach ($titles as $t) {
                        $label = trim((string) ($t['title'] ?? ''));
                        if ($label !== '') {
                            $titleBits[] = $label;
                        }
                    }
                    $killed = is_array($v['killed_session_ids'] ?? null) ? $v['killed_session_ids'] : [];
                    $ips = is_array($v['client_ips'] ?? null) ? $v['client_ips'] : [];
                    if ($ips === []) {
                        foreach ($titles as $t) {
                            $ip = trim((string) ($t['ip'] ?? ''));
                            if ($ip !== '') {
                                $ips[] = $ip;
                            }
                        }
                        $ips = array_values(array_unique($ips));
                    }
                    $actionLabel = (($v['action'] ?? '') === 'kill_newest_ips')
                        ? 'Cortar IPs más recientes'
                        : 'Cortar sesiones más recientes';
                ?>
                <tr>
                    <td class="small text-nowrap"><?= e($v['at'] ?? '') ?></td>
                    <td>
                        <div><?= e($v['display_name'] ?: ($v['username'] ?? '—')) ?></div>
                        <?php if (!empty($v['email'])): ?>
                        <div class="small text-muted"><?= e($v['email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($v['server_name'] ?: '—') ?></td>
                    <td><span class="badge bg-danger"><?= (int) ($v['stream_count'] ?? 0) ?></span></td>
                    <td><span class="badge bg-secondary"><?= (int) ($v['stream_limit'] ?? 0) ?></span></td>
                    <td class="small">
                        <div class="text-truncate" style="max-width: 280px" title="<?= e(implode(' · ', $titleBits)) ?>">
                            <?= e($titleBits !== [] ? implode(' · ', $titleBits) : '—') ?>
                        </div>
                        <?php if ($ips !== []): ?>
                        <div class="text-muted">IPs: <code><?= e(implode(', ', $ips)) ?></code></div>
                        <?php endif; ?>
                        <div class="text-muted">
                            <?= e($actionLabel) ?> (<?= count($killed) ?> sesión/es)
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($v['media_user_uuid'])): ?>
                        <a href="/media-users/<?= e($v['media_user_uuid']) ?>" class="btn btn-sm btn-outline-primary">Ver</a>
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
