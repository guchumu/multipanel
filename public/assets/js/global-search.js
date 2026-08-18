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

    function statusBadgeClass(status) {
        switch (status) {
            case 'active': return 'text-bg-success';
            case 'suspended': return 'text-bg-warning';
            case 'pending': return 'text-bg-info';
            case 'expired': return 'text-bg-secondary';
            default: return 'text-bg-light text-dark border';
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

    function setStatus(message, isError) {
        const cls = isError ? 'global-search-status is-error' : 'global-search-status';
        results.innerHTML = `<div class="${cls}">${escapeHtml(message)}</div>`;
        show();
        items = [];
        activeIndex = -1;
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
            const href = items[activeIndex].getAttribute('data-href');
            if (href) window.location.href = href;
        }
    }

    function render(users, q) {
        if (!users.length) {
            results.innerHTML = `<div class="global-search-empty">Sin resultados para “${escapeHtml(q)}”</div>`;
            show();
            items = [];
            activeIndex = -1;
            return;
        }

        results.innerHTML = users.map((u, i) => {
            const name = escapeHtml(u.display_name || u.username || 'Sin nombre');
            const email = escapeHtml(u.email || '');
            const server = escapeHtml(u.server_name || '');
            const tg = escapeHtml(u.telegram_chat_id || '');
            const href = `/media-users/${escapeHtml(u.uuid)}`;
            const metaParts = [
                email,
                server ? `Servidor: ${server}` : '',
                tg ? `TG: ${tg}` : '',
                `#${Number(u.id || 0)}`,
            ].filter(Boolean);
            const meta = metaParts.join(' · ');
            const status = String(u.status || '');
            const serverLink = u.server_uuid
                ? `<a href="/servers/${escapeHtml(u.server_uuid)}" class="global-search-server-link link-primary" tabindex="-1">abrir servidor</a>`
                : '';

            return `<div class="global-search-item"
                        role="option"
                        tabindex="-1"
                        id="globalSearchOpt${i}"
                        data-index="${i}"
                        data-href="${href}">
                <span class="global-search-avatar" aria-hidden="true"><i class="bi bi-person"></i></span>
                <span class="global-search-body">
                    <span class="global-search-title">
                        <span class="global-search-title-text">${name}</span>
                        ${serverLink}
                    </span>
                    <span class="global-search-meta">${meta}</span>
                </span>
                <span class="badge global-search-badge ${statusBadgeClass(status)}">${escapeHtml(statusLabel(status))}</span>
            </div>`;
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
        setStatus('Buscando…', false);

        try {
            const res = await fetch(`/media-users/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (mySeq !== seq) return;
            if (!res.ok) {
                setStatus(data.error || 'Error en la búsqueda', true);
                return;
            }
            render(data.users || [], q);
        } catch {
            if (mySeq !== seq) return;
            setStatus('Error de red', true);
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

    results.addEventListener('click', (e) => {
        if (e.target.closest('a.global-search-server-link')) {
            e.stopPropagation();
            return;
        }
        const item = e.target.closest('.global-search-item');
        if (!item) return;
        const href = item.getAttribute('data-href');
        if (href) window.location.href = href;
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
