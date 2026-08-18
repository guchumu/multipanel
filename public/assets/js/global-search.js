(function () {
    const input = document.getElementById('globalSearchInput');
    const results = document.getElementById('globalSearchResults');
    if (!input || !results) return;

    let timer = null;
    let seq = 0;
    let activeIndex = -1;
    let items = [];

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function statusLabel(status) {
        switch (status) {
            case 'active': return 'Activo';
            case 'suspended': return 'Suspendido';
            case 'pending': return 'Pendiente';
            case 'expired': return 'Caducado';
            default: return status || '';
        }
    }

    function hide() {
        results.classList.add('d-none');
        results.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
        items = [];
    }

    function show() {
        results.classList.remove('d-none');
        input.setAttribute('aria-expanded', 'true');
    }

    function highlight(delta) {
        if (!items.length) return;
        activeIndex = (activeIndex + delta + items.length) % items.length;
        items.forEach((el, i) => {
            el.classList.toggle('active', i === activeIndex);
            if (i === activeIndex) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function goActive() {
        if (activeIndex >= 0 && items[activeIndex]) {
            const href = items[activeIndex].getAttribute('href');
            if (href) window.location.href = href;
        }
    }

    function render(users, q) {
        if (!users.length) {
            results.innerHTML = `<div class="px-3 py-2 text-muted small">Sin resultados para “${escapeHtml(q)}”</div>`;
            show();
            items = [];
            return;
        }

        results.innerHTML = users.map((u, i) => {
            const name = escapeHtml(u.display_name || u.username || 'Sin nombre');
            const email = escapeHtml(u.email || '');
            const server = escapeHtml(u.server_name || '');
            const tg = escapeHtml(u.telegram_chat_id || '');
            const meta = [
                email,
                server ? `Servidor: ${server}` : '',
                tg ? `TG: ${tg}` : '',
                `#${Number(u.id || 0)}`,
            ].filter(Boolean).join(' · ');

            const serverLink = u.server_uuid
                ? `<a href="/servers/${escapeHtml(u.server_uuid)}" class="small text-decoration-none ms-1" tabindex="-1" onclick="event.stopPropagation()">servidor</a>`
                : '';

            return `<a href="/media-users/${escapeHtml(u.uuid)}"
                        class="dropdown-item global-search-item py-2"
                        role="option"
                        id="globalSearchOpt${i}"
                        data-index="${i}">
                <div class="d-flex justify-content-between gap-2 align-items-start">
                    <div class="min-w-0">
                        <div class="fw-medium text-truncate">${name}${serverLink}</div>
                        <div class="small text-muted text-truncate">${meta}</div>
                    </div>
                    <span class="badge bg-light text-dark border flex-shrink-0">${escapeHtml(statusLabel(u.status))}</span>
                </div>
            </a>`;
        }).join('');

        items = Array.from(results.querySelectorAll('.global-search-item'));
        activeIndex = -1;
        show();
    }

    async function runSearch() {
        const q = input.value.trim();
        if (q.length < 1 || (q.length < 2 && !/^\d+$/.test(q))) {
            hide();
            return;
        }

        const mySeq = ++seq;
        results.innerHTML = '<div class="px-3 py-2 text-muted small">Buscando…</div>';
        show();

        try {
            const res = await fetch(`/media-users/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (mySeq !== seq) return;
            if (!res.ok) {
                results.innerHTML = `<div class="px-3 py-2 text-danger small">${escapeHtml(data.error || 'Error en la búsqueda')}</div>`;
                show();
                return;
            }
            render(data.users || [], q);
        } catch {
            if (mySeq !== seq) return;
            results.innerHTML = '<div class="px-3 py-2 text-danger small">Error de red</div>';
            show();
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(runSearch, 250);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlight(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlight(-1);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0) {
                e.preventDefault();
                goActive();
            }
        } else if (e.key === 'Escape') {
            hide();
            input.blur();
        }
    });

    input.addEventListener('focus', () => {
        if (input.value.trim().length >= 1) runSearch();
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.global-search')) hide();
    });

    // Atajo "/" para enfocar (excepto en inputs/textarea)
    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target?.isContentEditable) return;
        e.preventDefault();
        input.focus();
        input.select();
    });
})();
