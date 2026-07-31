<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top px-3 px-lg-4">
    <button class="btn btn-outline-secondary d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas" aria-label="Menú">
        <i class="bi bi-list fs-5"></i>
    </button>
    <a class="navbar-brand d-lg-none me-auto text-truncate" href="/dashboard" style="max-width: 55vw;">
        <i class="bi bi-collection-play me-1"></i><?= e($title ?? 'MultiPanel') ?>
    </a>
    <span class="navbar-text d-none d-lg-inline text-muted small me-auto">
        <?= e($title ?? '') ?>
    </span>
    <div class="d-flex align-items-center gap-2 ms-lg-auto">
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-label="Idioma">
                <i class="bi bi-translate"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/locale/es?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard') ?>">Español</a></li>
                <li><a class="dropdown-item" href="/locale/en?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard') ?>">English</a></li>
            </ul>
        </div>
        <button class="btn btn-sm btn-outline-secondary" id="themeToggle" title="Cambiar tema" type="button">
            <i class="bi bi-moon-stars"></i>
        </button>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle me-1"></i><span class="d-none d-sm-inline"><?= e($user->fullName() ?? 'Usuario') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/settings"><i class="bi bi-gear me-2"></i><?= __('settings') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="/logout" method="POST">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>
