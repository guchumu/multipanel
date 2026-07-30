<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-4">
    <div class="d-flex align-items-center">
        <button class="btn btn-link d-lg-none" id="sidebarToggle"><i class="bi bi-list"></i></button>
    </div>
    <div class="ms-auto d-flex align-items-center gap-3">
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-translate"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/locale/es?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard') ?>">Español</a></li>
                <li><a class="dropdown-item" href="/locale/en?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard') ?>">English</a></li>
            </ul>
        </div>
        <button class="btn btn-sm btn-outline-secondary" id="themeToggle" title="Cambiar tema">
            <i class="bi bi-moon-stars"></i>
        </button>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle me-1"></i><?= e($user->fullName() ?? 'Usuario') ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Perfil</a></li>
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
