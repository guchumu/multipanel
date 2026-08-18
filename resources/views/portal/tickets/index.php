<?php
$statusEs = static function (string $s): string {
    return match ($s) {
        'open' => 'Abierto',
        'in_progress' => 'En curso',
        'waiting' => 'En espera',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
        default => $s,
    };
};
$statusClass = static function (string $s): string {
    return match ($s) {
        'open' => 'primary',
        'in_progress' => 'info',
        'waiting' => 'warning',
        'resolved' => 'success',
        'closed' => 'secondary',
        default => 'secondary',
    };
};
ob_start();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="portal-page-title mb-0">Mis tickets</h1>
    <a href="/portal/tickets/create" class="btn btn-light btn-sm">Nuevo ticket</a>
</div>
<p class="portal-page-lead">Consulta el estado de tus consultas o abre una nueva.</p>

<div class="card portal-card">
    <div class="list-group list-group-flush">
        <?php if (empty($tickets)): ?>
        <div class="list-group-item text-center text-muted py-4">
            No tienes tickets todavía.
            <div class="mt-2"><a href="/portal/tickets/create">Abrir el primero</a></div>
        </div>
        <?php else: ?>
        <?php foreach ($tickets as $t): ?>
        <a href="/portal/tickets/<?= e($t['uuid']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
            <span class="text-truncate"><?= e($t['subject']) ?></span>
            <span class="badge text-bg-<?= e($statusClass((string) $t['status'])) ?>"><?= e($statusEs((string) $t['status'])) ?></span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<p class="text-white-50 small mt-3 mb-0"><a class="link-light" href="/portal">← Volver al inicio</a></p>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/portal.php'); ?>
