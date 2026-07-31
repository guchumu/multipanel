(function () {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    const searchInput = document.getElementById('userSearch');
    const tbody = document.getElementById('usersTableBody');
    const meta = document.getElementById('userSearchMeta');
    if (!searchInput || !tbody) return;

    const initialHtml = tbody.innerHTML;
    let timer = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function renderRows(users) {
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Sin resultados</td></tr>';
            return;
        }

        tbody.innerHTML = users.map((u) => {
            const active = u.status === 'active';
            const serverBadge = u.server_name
                ? `<span class="badge bg-light text-dark border">${escapeHtml(u.server_name)}</span>`
                : '<span class="text-muted">—</span>';
            const actionBtn = active
                ? `<button class="btn btn-outline-warning" onclick="suspendUser('${escapeHtml(u.uuid)}')"><i class="bi bi-pause"></i></button>`
                : `<button class="btn btn-outline-success" onclick="activateUser('${escapeHtml(u.uuid)}')"><i class="bi bi-play"></i></button>`;

            return `<tr>
                <td>${escapeHtml(u.username)}</td>
                <td class="small">${escapeHtml(u.email || '-')}</td>
                <td class="small">${serverBadge}</td>
                <td><span class="badge bg-secondary">${escapeHtml(u.status)}</span></td>
                <td>${Number(u.max_streams || 0)}</td>
                <td class="small">
                    <input type="date" class="form-control form-control-sm expires-input" data-uuid="${escapeHtml(u.uuid)}"
                           value="${escapeHtml(u.expires_at || '')}">
                </td>
                <td class="small" style="min-width: 120px;">
                    <input type="text" class="form-control form-control-sm telegram-input" data-uuid="${escapeHtml(u.uuid)}"
                           value="${escapeHtml(u.telegram_chat_id || '')}" placeholder="Chat ID" title="Telegram Chat ID para enviar mensajes">
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="/media-users/${escapeHtml(u.uuid)}/messages" class="btn btn-outline-info" title="Historial mensajes"><i class="bi bi-chat-dots"></i></a>
                        ${actionBtn}
                    </div>
                </td>
            </tr>`;
        }).join('');

        bindInlineEditors();
    }

    function bindInlineEditors() {
        document.querySelectorAll('.expires-input').forEach((input) => {
            input.addEventListener('change', async function () {
                const res = await fetch(`/media-users/${this.dataset.uuid}/expires`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ expires_at: this.value }),
                });
                if (!res.ok) alert('Error al guardar fecha');
            });
        });

        document.querySelectorAll('.telegram-input').forEach((input) => {
            input.addEventListener('change', async function () {
                const res = await fetch(`/media-users/${this.dataset.uuid}/telegram`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ telegram_chat_id: this.value }),
                });
                if (!res.ok) alert('Error al guardar Telegram');
            });
        });
    }

    async function runSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) {
            tbody.innerHTML = initialHtml;
            meta?.classList.add('d-none');
            bindInlineEditors();
            return;
        }

        const params = new URLSearchParams({ q });
        const status = new URLSearchParams(window.location.search).get('status');
        const serverId = new URLSearchParams(window.location.search).get('server_id');
        if (status) params.set('status', status);
        if (serverId) params.set('server_id', serverId);

        try {
            const res = await fetch(`/media-users/search?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            renderRows(data.users || []);
            if (meta) {
                meta.textContent = `${data.count || 0} resultado(s) para "${q}"`;
                meta.classList.remove('d-none');
            }
        } catch (e) {
            if (meta) {
                meta.textContent = 'Error en la búsqueda';
                meta.classList.remove('d-none');
            }
        }
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(runSearch, 250);
    });

    bindInlineEditors();
})();
