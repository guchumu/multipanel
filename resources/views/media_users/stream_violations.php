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
            Se guardan <strong>todas</strong> las reproducciones concurrentes (IPs, reproductores, títulos…).
            Límite por defecto: <?= (int) $defaultMaxStreams ?> ·
            Corte automático: <?= !empty($enforcementEnabled) ? 'activo' : 'desactivado' ?>
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
                    <th>Reproducciones (detalle)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($violations)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        Sin incumplimientos registrados todavía.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($violations as $v): ?>
                <?php
                    $titles = is_array($v['titles'] ?? null) ? $v['titles'] : [];
                    $killed = is_array($v['killed_session_ids'] ?? null) ? $v['killed_session_ids'] : [];
                    $killedSet = array_fill_keys(array_map('strval', $killed), true);
                    $action = (string) ($v['action'] ?? '');
                    $actionLabel = match ($action) {
                        'kill_newest_ips' => 'Cortar IPs más recientes',
                        'kill_newest' => 'Cortar sesiones más recientes',
                        'detected' => 'Detectado (sin corte)',
                        default => $action !== '' ? $action : 'Detectado',
                    };
                ?>
                <tr>
                    <td class="small text-nowrap"><?= e($v['at'] ?? '') ?></td>
                    <td>
                        <div><?= e($v['display_name'] ?: ($v['username'] ?? '—')) ?></div>
                        <?php if (!empty($v['email'])): ?>
                        <div class="small text-muted"><?= e($v['email']) ?></div>
                        <?php endif; ?>
                        <div class="small text-muted mt-1"><?= e($actionLabel) ?></div>
                    </td>
                    <td class="small"><?= e($v['server_name'] ?: '—') ?></td>
                    <td>
                        <span class="badge bg-danger"><?= (int) ($v['stream_count'] ?? 0) ?></span>
                        <span class="text-muted">/</span>
                        <span class="badge bg-secondary"><?= (int) ($v['stream_limit'] ?? 0) ?></span>
                    </td>
                    <td class="small" style="min-width: 280px; max-width: 520px;">
                        <?php if ($titles === []): ?>
                        <span class="text-muted">Sin detalle de sesiones</span>
                        <?php else: ?>
                        <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                            <?php foreach ($titles as $i => $t): ?>
                            <?php
                                if (!is_array($t)) {
                                    continue;
                                }
                                $sid = (string) ($t['session_id'] ?? '');
                                $wasKilled = !empty($t['killed']) || ($sid !== '' && isset($killedSet[$sid]));
                                $title = trim((string) ($t['title'] ?? '')) ?: 'Sin título';
                                $subtitle = trim((string) ($t['subtitle'] ?? ''));
                                $ip = trim((string) ($t['ip'] ?? ''));
                                $player = trim((string) ($t['player'] ?? ''));
                                $product = trim((string) ($t['product'] ?? ''));
                                $platform = trim((string) ($t['platform'] ?? ''));
                                $state = trim((string) ($t['state'] ?? ''));
                                $progress = (int) ($t['progress'] ?? 0);
                                $playMethod = trim((string) ($t['play_method'] ?? ''));
                                $location = trim((string) ($t['location'] ?? ''));
                                $bandwidth = trim((string) ($t['bandwidth'] ?? ''));
                                $deviceBits = array_values(array_filter([$player, $product, $platform], static fn (string $x): bool => $x !== ''));
                            ?>
                            <li class="border rounded px-2 py-1 <?= $wasKilled ? 'border-warning bg-warning-subtle' : 'bg-light' ?>">
                                <div class="fw-medium">
                                    #<?= (int) ($i + 1) ?> · <?= e($title) ?>
                                    <?php if ($wasKilled): ?>
                                    <span class="badge text-bg-warning ms-1">Cortada</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($subtitle !== ''): ?>
                                <div class="text-muted"><?= e($subtitle) ?></div>
                                <?php endif; ?>
                                <div class="text-muted">
                                    IP: <code><?= e($ip !== '' ? $ip : '—') ?></code>
                                    <?php if ($deviceBits !== []): ?>
                                    · <?= e(implode(' / ', $deviceBits)) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted">
                                    <?php if ($state !== ''): ?>Estado: <?= e($state) ?> · <?php endif; ?>
                                    Progreso: <?= (int) $progress ?>%
                                    <?php if ($playMethod !== ''): ?> · <?= e($playMethod) ?><?php endif; ?>
                                    <?php if ($location !== ''): ?> · <?= e($location) ?><?php endif; ?>
                                    <?php if ($bandwidth !== ''): ?> · <?= e($bandwidth) ?><?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
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
