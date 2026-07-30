<?php ob_start(); ?>
<h4 class="text-white mb-4">Hola, <?= e($portalUser->display_name ?? $portalUser->username) ?></h4>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card portal-card">
            <div class="card-body text-center">
                <i class="bi bi-shield-check text-success fs-1"></i>
                <h6 class="mt-2">Estado cuenta</h6>
                <span class="badge bg-<?= $portalUser->status === 'active' ? 'success' : 'warning' ?>"><?= e($portalUser->status) ?></span>
                <?php if ($portalUser->expires_at): ?>
                <p class="small text-muted mt-2 mb-0">Expira: <?= e($portalUser->expires_at) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card portal-card">
            <div class="card-body text-center">
                <i class="bi bi-credit-card text-primary fs-1"></i>
                <h6 class="mt-2">Suscripción</h6>
                <?php if ($subscription): ?>
                <strong><?= e($subscription['plan_name']) ?></strong>
                <p class="small text-muted mb-0"><?= number_format((float)$subscription['price'], 2) ?> € / <?= e($subscription['interval']) ?></p>
                <?php else: ?>
                <a href="/portal/subscription" class="btn btn-sm btn-outline-primary">Contratar plan</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card portal-card">
            <div class="card-body text-center">
                <i class="bi bi-play-btn text-info fs-1"></i>
                <h6 class="mt-2">Streams</h6>
                <h3 class="mb-0"><?= (int) $portalUser->max_streams ?></h3>
                <p class="small text-muted mb-0">simultáneos</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-md-6">
        <div class="card portal-card">
            <div class="card-header bg-white"><h6 class="mb-0">Reproducciones recientes</h6></div>
            <ul class="list-group list-group-flush">
                <?php if (empty($recentPlays)): ?>
                <li class="list-group-item text-muted text-center">Sin reproducciones</li>
                <?php else: ?>
                <?php foreach ($recentPlays as $play): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span><?= e($play['title'] ?? 'Desconocido') ?></span>
                    <small class="text-muted"><?= e($play['started_at']) ?></small>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card portal-card">
            <div class="card-header bg-white d-flex justify-content-between">
                <h6 class="mb-0">Tickets soporte</h6>
                <a href="/portal/tickets/create" class="btn btn-sm btn-primary">Nuevo</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($tickets)): ?>
                <li class="list-group-item text-muted text-center">Sin tickets</li>
                <?php else: ?>
                <?php foreach ($tickets as $t): ?>
                <li class="list-group-item">
                    <a href="/portal/tickets/<?= e($t['uuid']) ?>"><?= e($t['subject']) ?></a>
                    <span class="badge bg-secondary float-end"><?= e($t['status']) ?></span>
                </li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
