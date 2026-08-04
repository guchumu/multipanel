/**
 * MultiPanel ERP - Frontend JavaScript
 */
(function () {
    'use strict';

    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        const saved = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', saved);
        updateThemeIcon(saved);

        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-bs-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            updateThemeIcon(next);
        });
    }

    function updateThemeIcon(theme) {
        const icon = themeToggle?.querySelector('i');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
        }
    }

    const offcanvasEl = document.getElementById('sidebarOffcanvas');
    if (offcanvasEl && typeof bootstrap !== 'undefined') {
        const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
        offcanvasEl.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => offcanvas.hide());
        });
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrfToken) {
        const originalFetch = window.fetch;
        window.fetch = function (url, options = {}) {
            options = options || {};
            options.credentials = options.credentials || 'same-origin';
            options.headers = options.headers || {};
            const method = (options.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD') {
                if (options.headers instanceof Headers) {
                    if (!options.headers.has('X-CSRF-TOKEN')) {
                        options.headers.set('X-CSRF-TOKEN', csrfToken);
                    }
                } else if (!options.headers['X-CSRF-TOKEN'] && !options.headers['X-Csrf-Token']) {
                    options.headers['X-CSRF-TOKEN'] = csrfToken;
                }
            }
            return originalFetch(url, options);
        };
    }

    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            alert.querySelector('.btn-close')?.click();
        }, 5000);
    });

    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
})();
