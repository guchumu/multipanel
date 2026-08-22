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

    /* —— Sidebar collapse (desktop) —— */
    const SIDEBAR_KEY = 'mp_sidebar_collapsed';
    const sidebarToggle = document.getElementById('sidebarToggle');

    function applySidebarCollapsed(collapsed) {
        document.documentElement.classList.toggle('sidebar-collapsed', !!collapsed);
        document.body.classList.toggle('sidebar-collapsed', !!collapsed);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            sidebarToggle.title = collapsed ? 'Ampliar menú' : 'Contraer menú a iconos';
            const icon = sidebarToggle.querySelector('i');
            if (icon) {
                icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar';
            }
        }
        try {
            localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
            document.cookie = 'mp_sidebar_collapsed=' + (collapsed ? '1' : '0')
                + '; path=/; max-age=31536000; SameSite=Lax';
        } catch (_) { /* ignore */ }
    }

    try {
        const savedSidebar = localStorage.getItem(SIDEBAR_KEY);
        if (savedSidebar === '1') {
            applySidebarCollapsed(true);
        } else if (document.documentElement.classList.contains('sidebar-collapsed')) {
            applySidebarCollapsed(true);
        }
    } catch (_) { /* ignore */ }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            const collapsed = !(
                document.body.classList.contains('sidebar-collapsed')
                || document.documentElement.classList.contains('sidebar-collapsed')
            );
            applySidebarCollapsed(collapsed);
        });
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
        // Transcode ya va a texto completo; permitir colapsar/expandir igual
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

    /* —— Modal mensaje / detener (X en carátula) —— */
    function ensureKillModal() {
        let modalEl = document.getElementById('sessionKillModal');
        if (modalEl) return modalEl;

        modalEl = document.createElement('div');
        modalEl.id = 'sessionKillModal';
        modalEl.className = 'modal fade';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-labelledby', 'sessionKillModalLabel');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML = `
<div class="modal-dialog modal-dialog-centered">
  <div class="modal-content">
    <div class="modal-header py-2">
      <h5 class="modal-title fs-6" id="sessionKillModalLabel">Mensaje / detener reproducción</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="killModalServerId" value="">
      <input type="hidden" id="killModalSessionId" value="">
      <label class="form-label small mb-1" for="killModalPreset">Mensaje predefinido</label>
      <select class="form-select form-select-sm mb-2" id="killModalPreset" aria-label="Mensaje predefinido">
        <option value="">Personalizado / sin mensaje</option>
      </select>
      <label class="form-label small mb-1" for="killModalMessage">Mensaje al usuario (opcional)</label>
      <textarea class="form-control form-control-sm" id="killModalMessage" rows="3" maxlength="500" placeholder="Mensaje al usuario (opcional)"></textarea>
    </div>
    <div class="modal-footer py-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-danger btn-sm" id="killModalConfirm">
        <i class="bi bi-stop-circle me-1"></i>Pausar / detener
      </button>
    </div>
  </div>
</div>`;
        document.body.appendChild(modalEl);

        const presetSelect = modalEl.querySelector('#killModalPreset');
        const textarea = modalEl.querySelector('#killModalMessage');
        presetSelect.addEventListener('change', () => {
            if (!presetSelect.value) {
                textarea.value = '';
                return;
            }
            const messages = window.MP_STOP_MESSAGES || [];
            const preset = messages.find(m => String(m.id) === String(presetSelect.value));
            textarea.value = preset ? String(preset.body || '') : '';
        });

        modalEl.querySelector('#killModalConfirm').addEventListener('click', async () => {
            const btn = modalEl.querySelector('#killModalConfirm');
            const serverId = modalEl.querySelector('#killModalServerId').value;
            const sessionId = modalEl.querySelector('#killModalSessionId').value;
            const message = (textarea.value || '').trim();
            const confirmText = message
                ? '¿Detener esta reproducción y enviar el mensaje al usuario?'
                : '¿Detener esta reproducción?';
            if (!confirm(confirmText)) return;

            btn.disabled = true;
            const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
            if (!csrf) {
                alert('No hay token CSRF. Recarga la página (F5).');
                btn.disabled = false;
                return;
            }
            try {
                const body = new URLSearchParams({
                    _token: csrf,
                    server_id: serverId,
                    session_id: sessionId,
                    message: message,
                });
                const res = await fetch('/activity/kill', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Csrf-Token': csrf,
                    },
                    body,
                });
                const data = await res.json().catch(() => ({}));
                if (data.success) {
                    if (typeof bootstrap !== 'undefined') {
                        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    }
                    const killed = document.querySelector(`.session-card[data-session-id="${CSS.escape(sessionId)}"]`);
                    const killedCol = killed?.closest('.session-col');
                    if (killedCol) killedCol.remove();
                    else killed?.remove();
                    if (typeof window.MP_REFRESH_SESSIONS === 'function') {
                        window.MP_REFRESH_SESSIONS();
                    }
                } else {
                    alert(data.message || 'No se pudo detener');
                }
            } catch (err) {
                alert('Error de red');
            } finally {
                btn.disabled = false;
            }
        });

        return modalEl;
    }

    function fillKillPresets(select) {
        const messages = window.MP_STOP_MESSAGES || [];
        let html = '<option value="">Personalizado / sin mensaje</option>';
        let defaultBody = '';
        messages.forEach(m => {
            const isDef = Number(m.is_default) === 1;
            if (isDef) defaultBody = String(m.body || '');
            html += `<option value="${m.id}"${isDef ? ' selected' : ''}>${escapeAttr(m.title)}${isDef ? ' ★' : ''}</option>`;
        });
        if (!defaultBody && messages.length) {
            defaultBody = String(messages[0].body || '');
        }
        select.innerHTML = html;
        return defaultBody;
    }

    function escapeAttr(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ch]));
    }

    function openKillModal(serverId, sessionId) {
        if (!serverId || !sessionId) return;
        const modalEl = ensureKillModal();
        modalEl.querySelector('#killModalServerId').value = String(serverId);
        modalEl.querySelector('#killModalSessionId').value = String(sessionId);
        const defaultBody = fillKillPresets(modalEl.querySelector('#killModalPreset'));
        modalEl.querySelector('#killModalMessage').value = defaultBody;
        if (typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    document.addEventListener('click', function (e) {
        const smsBtn = e.target.closest('.session-poster-sms');
        if (smsBtn) {
            e.preventDefault();
            e.stopPropagation();
            const card = smsBtn.closest('.session-card');
            const serverId = smsBtn.dataset.serverId || card?.dataset.serverId || '';
            const sessionId = smsBtn.dataset.sessionId || card?.dataset.sessionId || '';
            openKillModal(serverId, sessionId);
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
        if (e.target.closest('a, button, input, select, textarea')) {
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
