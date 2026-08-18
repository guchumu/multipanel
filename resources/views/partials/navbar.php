<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top px-3 px-lg-4 flex-wrap gap-1">
    <button class="btn btn-outline-secondary d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas" aria-label="Menú">
        <i class="bi bi-list fs-5"></i>
    </button>
    <button class="btn btn-outline-secondary d-none d-lg-inline-flex me-2" type="button" id="sidebarToggle" title="Ocultar menú lateral" aria-pressed="false" aria-label="Ocultar o mostrar menú lateral">
        <i class="bi bi-layout-sidebar"></i>
    </button>
    <a class="navbar-brand d-lg-none text-truncate" href="/dashboard" style="max-width: 40vw;">
        <i class="bi bi-collection-play me-1"></i><?= e($title ?? 'MultiPanel') ?>
    </a>
    <span class="navbar-text d-none d-lg-inline text-muted small me-2 text-truncate" style="max-width: 12rem;">
        <?= e($title ?? '') ?>
    </span>
    <div class="global-search flex-grow-1 mx-1 mx-lg-3 position-relative" style="max-width: 28rem;">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="search"
                   id="globalSearchInput"
                   class="form-control border-start-0"
                   placeholder="Buscar usuario, email, Telegram, UUID…"
                   autocomplete="off"
                   aria-label="Búsqueda global de usuarios"
                   aria-controls="globalSearchResults"
                   aria-expanded="false"
                   role="combobox">
            <kbd class="input-group-text d-none d-md-inline bg-light text-muted small" title="Atajo">/</kbd>
        </div>
        <div id="globalSearchResults"
             class="global-search-results dropdown-menu shadow border-0 w-100 mt-1 d-none"
             role="listbox"
             aria-label="Resultados de búsqueda"></div>
    </div>
    <div class="d-flex align-items-center gap-2 ms-lg-auto flex-shrink-0">
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
                <i class="bi bi-person-circle me-1"></i><span class="d-none d-sm-inline"><?= e(is_object($user) && method_exists($user, 'fullName') ? $user->fullName() : ($user->username ?? 'Usuario')) ?></span>
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
