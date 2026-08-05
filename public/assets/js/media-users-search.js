(function () {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    const searchInput = document.getElementById('userSearch');
    const tbody = document.getElementById('usersTableBody');
    const meta = document.getElementById('userSearchMeta');
    if (!searchInput || !tbody) return;

    const initialHtml = tbody.innerHTML;
    let timer = null;
    let requestSeq = 0;
    const countSummary = document.getElementById('usersCountSummary');
    const initialCountHtml = countSummary?.innerHTML || '';

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function statusBadgeClass(status) {
        switch (status) {
            case 'active': return 'bg-success';
            case 'suspended': return 'bg-warning text-dark';
            case 'pending': return 'bg-secondary';
            default: return 'bg-light text-dark border';
        }
    }

    function statusLabel(status) {
        switch (status) {
            case 'active': return 'Activo';
            case 'suspended': return 'Suspendido';
            case 'pending': return 'Pendiente';
            case 'invited': return 'Invitado';
            case 'inactive': return 'Inactivo';
            default: return status;
        }
    }

    function membershipBadge(onServer) {
        if (onServer === null || onServer === undefined || onServer === '') {
            return { label: 'Sin sync', cls: 'bg-light text-dark border' };
        }
        if (Number(onServer) === 1) {
            return { label: 'En biblioteca', cls: 'bg-success' };
        }
        return { label: 'No está en el servidor', cls: 'bg-danger' };
    }

    function daysLeftBadge(expiresAt) {
        if (!expiresAt) return { label: 'Sin fecha', cls: 'bg-light text-dark border' };
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const expires = new Date(expiresAt + 'T00:00:00');
        if (Number.isNaN(expires.getTime())) return { label: 'Sin fecha', cls: 'bg-light text-dark border' };
        const days = Math.floor((expires.getTime() - today.getTime()) / 86400000);

        if (days < 0) return { label: `Caducó hace ${Math.abs(days)}d`, cls: 'bg-dark' };
        if (days === 0) return { label: 'Caduca hoy', cls: 'bg-danger' };
        if (days <= 3) return { label: `Quedan ${days}d`, cls: 'bg-danger' };
        if (days <= 7) return { label: `Quedan ${days}d`, cls: 'bg-warning text-dark' };
        if (days <= 30) return { label: `Quedan ${days}d`, cls: 'bg-info text-dark' };
        return { label: `Quedan ${days}d`, cls: 'bg-light text-dark border' };
    }

    function renderRows(users) {
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">Sin resultados</td></tr>';
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
            const dl = daysLeftBadge(u.expires_at);
            const mb = membershipBadge(u.on_server);

            return `<tr>
                <td class="small text-muted">${Number(u.id || 0)}</td>
                <td><a href="/media-users/${escapeHtml(u.uuid)}" class="fw-medium text-decoration-none">${escapeHtml(u.username)}</a></td>
                <td class="small">${escapeHtml(u.email || '-')}</td>
                <td class="small">${serverBadge}</td>
                <td><span class="badge ${statusBadgeClass(u.status)}">${escapeHtml(statusLabel(u.status))}</span></td>
                <td><span class="badge ${mb.cls}">${escapeHtml(mb.label)}</span></td>
                <td>${Number(u.max_streams || 0)}</td>
                <td class="small">
                    <input type="date" class="form-control form-control-sm expires-input" data-uuid="${escapeHtml(u.uuid)}"
                           value="${escapeHtml(u.expires_at || '')}">
                </td>
                <td class="small text-nowrap"><span class="badge ${dl.cls}">${escapeHtml(dl.label)}</span></td>
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
    }

    tbody.addEventListener('change', async (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;

        if (input.classList.contains('expires-input')) {
            const res = await fetch(`/media-users/${input.dataset.uuid}/expires`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ expires_at: input.value }),
            });
            if (!res.ok) alert('Error al guardar fecha');
            return;
        }

        if (input.classList.contains('telegram-input')) {
            const res = await fetch(`/media-users/${input.dataset.uuid}/telegram`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ telegram_chat_id: input.value }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                alert(data.error || data.message || 'Error al guardar Telegram');
            }
        }
    });

    async function runSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) {
            tbody.innerHTML = initialHtml;
            meta?.classList.add('d-none');
            if (countSummary) countSummary.innerHTML = initialCountHtml;
            return;
        }

        const params = new URLSearchParams({ q });
        const status = new URLSearchParams(window.location.search).get('status');
        const serverId = new URLSearchParams(window.location.search).get('server_id');
        const onServer = new URLSearchParams(window.location.search).get('on_server');
        if (status) params.set('status', status);
        if (serverId) params.set('server_id', serverId);
        if (onServer === '0' || onServer === '1') params.set('on_server', onServer);

        const seq = ++requestSeq;
        if (meta) {
            meta.textContent = 'Buscando…';
            meta.classList.remove('d-none');
        }

        try {
            const res = await fetch(`/media-users/search?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            const data = await res.json().catch(() => ({}));
            if (seq !== requestSeq) return;

            if (!res.ok) {
                if (meta) {
                    meta.textContent = data.error || `Error en la búsqueda (${res.status})`;
                    meta.classList.remove('d-none');
                }
                return;
            }

            renderRows(data.users || []);
            if (countSummary) {
                const total = data.total ?? data.count ?? 0;
                countSummary.innerHTML = `Mostrando <strong>${data.count || 0}</strong> de <strong>${total}</strong> usuarios <span class="text-muted">(búsqueda: "${escapeHtml(q)}")</span>`;
            }
            if (meta) {
                meta.textContent = `${data.count || 0} resultado(s) para "${q}"`;
                meta.classList.remove('d-none');
            }
        } catch (e) {
            if (seq !== requestSeq) return;
            if (meta) {
                meta.textContent = 'Error de red en la búsqueda';
                meta.classList.remove('d-none');
            }
        }
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(runSearch, 300);
    });
})();
