/**
 * MultiPanel ERP - Frontend JavaScript
 */
(function () {
    'use strict';

    // Theme toggle
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

    // Sidebar toggle (mobile)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
    }

    // CSRF for fetch
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrfToken) {
        const originalFetch = window.fetch;
        window.fetch = function (url, options = {}) {
            options.headers = options.headers || {};
            if (options.method && options.method !== 'GET') {
                if (options.headers instanceof Headers) {
                    options.headers.set('X-CSRF-TOKEN', csrfToken);
                } else {
                    options.headers['X-CSRF-TOKEN'] = csrfToken;
                }
            }
            return originalFetch(url, options);
        };
    }

    // Auto-dismiss alerts
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const closeBtn = alert.querySelector('.btn-close');
            closeBtn?.click();
        }, 5000);
    });
})();
