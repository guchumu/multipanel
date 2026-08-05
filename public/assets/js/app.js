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

    /**
     * En directo: Q/S/C/V/A/Sub compacto con ellipsis;
     * clic en el bloque (también tras polling) expande el texto completo.
     */
    function toggleSessionStreamInfo(info) {
        if (!info) return;
        const expanded = info.classList.toggle('expanded');
        info.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        info.setAttribute(
            'title',
            expanded ? 'Clic para ocultar el detalle' : 'Clic para ver el detalle completo'
        );
        const toggle = info.querySelector('.stream-info-toggle');
        if (toggle) {
            toggle.textContent = expanded ? 'Ver menos' : 'Ver más';
        }
    }

    function toggleSessionTitle(titleEl) {
        if (!titleEl) return;
        const expanded = titleEl.classList.toggle('expanded');
        titleEl.classList.toggle('text-truncate', !expanded);
        titleEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        const full = (titleEl.textContent || '').trim();
        titleEl.setAttribute(
            'title',
            expanded
                ? (full + ' — clic para compactar')
                : (full + ' — clic para ver completo')
        );
    }

    function focusKillMessageBox(card) {
        if (!card) return;
        const box = card.querySelector('.kill-message-box');
        if (!box) return;
        box.classList.remove('kill-message-box-focus');
        // Re-trigger CSS animation
        void box.offsetWidth;
        box.classList.add('kill-message-box-focus');
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const textarea = box.querySelector('.kill-message-input');
        if (textarea) {
            textarea.focus({ preventScroll: true });
            const len = textarea.value.length;
            try {
                textarea.setSelectionRange(len, len);
            } catch (_) { /* ignore */ }
        }
        window.setTimeout(() => box.classList.remove('kill-message-box-focus'), 1600);
    }

    document.addEventListener('click', function (e) {
        const smsBtn = e.target.closest('.session-poster-sms');
        if (smsBtn) {
            e.preventDefault();
            e.stopPropagation();
            focusKillMessageBox(smsBtn.closest('.session-card'));
            return;
        }

        const titleEl = e.target.closest('.session-title');
        if (titleEl && !e.target.closest('a, button, input, select, textarea')) {
            e.preventDefault();
            toggleSessionTitle(titleEl);
            return;
        }

        const info = e.target.closest('.session-stream-info');
        if (!info) return;
        // No interferir con controles de detener/mensaje
        if (e.target.closest('.kill-message-box, .btn-kill-session, a, button, input, select, textarea')) {
            return;
        }
        toggleSessionStreamInfo(info);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;

        const titleEl = e.target.closest('.session-title');
        if (titleEl && e.target === titleEl) {
            e.preventDefault();
            toggleSessionTitle(titleEl);
            return;
        }

        const info = e.target.closest('.session-stream-info');
        if (!info || e.target !== info) return;
        e.preventDefault();
        toggleSessionStreamInfo(info);
    });
})();
