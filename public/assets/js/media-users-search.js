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

    function serviceBadgeHtml(type) {
        const t = String(type || '').toLowerCase();
        if (t === 'plex') return '<span class="badge badge-service-plex">Plex</span>';
        if (t === 'jellyfin') return '<span class="badge badge-service-jellyfin">Jellyfin</span>';
        return '';
    }

    function normalizeTelegram(value) {
        const tg = String(value ?? '').trim();
        if (!tg || tg.toLowerCase() === 'null') return '';
        return tg;
    }

    function statusBadgeClass(status) {
        switch (status) {
            case 'active': return 'bg-success';
            case 'suspended': return 'bg-warning text-dark';
            case 'expired': return 'bg-secondary';
            case 'pending': return 'bg-secondary';
            case 'invited': return 'bg-info text-dark';
            default: return 'bg-light text-dark border';
        }
    }

    function statusLabel(status) {
        switch (status) {
            case 'active': return 'Activo';
            case 'suspended': return 'Suspendido';
            case 'expired': return 'Caducado';
            case 'pending': return 'Pendiente';
            case 'invited': return 'Invitado';
            case 'inactive': return 'Inactivo';
            default: return status;
        }
    }

    function effectiveStatus(user) {
        const dbStatus = String(user.status || '');
        if ((dbStatus === 'active' || dbStatus === 'invited') && user.expires_at) {
            const dl = daysLeftBadge(user.expires_at);
            if (dl.label.startsWith('Caducó')) {
                return { key: 'expired', label: 'Caducado', cls: 'bg-secondary' };
            }
        }
        return { key: dbStatus, label: statusLabel(dbStatus), cls: statusBadgeClass(dbStatus) };
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

    function normalizeExpiresDate(value) {
        if (!value) return '';
        const datePart = String(value).slice(0, 10);
        const m = datePart.match(/^(\d{1,4})-(\d{2})-(\d{2})$/);
        if (!m) return datePart;
        let y = Number(m[1]);
        if (y >= 0 && y < 100) y += 2000;
        if (y >= 100 && y < 1000) y += 2000;
        return `${String(y).padStart(4, '0')}-${m[2]}-${m[3]}`;
    }

    function daysLeftBadge(expiresAt) {
        if (!expiresAt) return { label: 'Sin fecha', cls: 'bg-light text-dark border' };
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const datePart = normalizeExpiresDate(expiresAt);
        const expires = new Date(datePart + 'T00:00:00');
        if (Number.isNaN(expires.getTime())) return { label: 'Sin fecha', cls: 'bg-light text-dark border' };
        const days = Math.floor((expires.getTime() - today.getTime()) / 86400000);

        if (days < 0) return { label: `Caducó hace ${Math.abs(days)}d`, cls: 'bg-dark' };
        if (days === 0) return { label: 'Caduca hoy', cls: 'bg-danger' };
        if (days <= 3) return { label: `Quedan ${days}d`, cls: 'bg-danger' };
        if (days <= 7) return { label: `Quedan ${days}d`, cls: 'bg-warning text-dark' };
        if (days <= 30) return { label: `Quedan ${days}d`, cls: 'bg-info text-dark' };
        return { label: `Quedan ${days}d`, cls: 'bg-light text-dark border' };
    }

    function listQueryParams() {
        const url = new URLSearchParams(window.location.search);
        const params = new URLSearchParams();
        ['status', 'server_id', 'on_server', 'sort', 'dir', 'filter_empty'].forEach((key) => {
            const value = url.get(key);
            if (value !== null && value !== '') params.set(key, value);
        });
        return params;
    }

    function renderRows(users) {
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">Sin resultados</td></tr>';
            return;
        }

        tbody.innerHTML = users.map((u) => {
            const dbStatus = String(u.status || '');
            const isActive = dbStatus === 'active';
            const isExpiredStatus = dbStatus === 'expired';
            const st = effectiveStatus(u);
            const actionBtns = isActive
                ? `<button class="btn btn-outline-warning" onclick="suspendUser('${escapeHtml(u.uuid)}')" title="Pausar"><i class="bi bi-pause"></i></button>
                   <button class="btn btn-outline-secondary" onclick="expireUser('${escapeHtml(u.uuid)}')" title="Marcar caducado"><i class="bi bi-hourglass-bottom"></i></button>`
                : `<button class="btn btn-outline-success" onclick="activateUser('${escapeHtml(u.uuid)}')" title="Activar"><i class="bi bi-play"></i></button>`;
            const statusMenu = [];
            if (!isActive && !isExpiredStatus) {
                statusMenu.push(`<li><button type="button" class="dropdown-item text-success" onclick="activateUser('${escapeHtml(u.uuid)}')">Activar acceso</button></li>`);
            }
            if (isActive) {
                statusMenu.push(`<li><button type="button" class="dropdown-item text-warning" onclick="suspendUser('${escapeHtml(u.uuid)}')">Pausar acceso</button></li>`);
                statusMenu.push(`<li><button type="button" class="dropdown-item text-secondary" onclick="expireUser('${escapeHtml(u.uuid)}')">Marcar caducado</button></li>`);
            } else if (!isExpiredStatus) {
                statusMenu.push(`<li><button type="button" class="dropdown-item text-secondary" onclick="expireUser('${escapeHtml(u.uuid)}')">Marcar caducado</button></li>`);
            }
            statusMenu.push(`<li><hr class="dropdown-divider"></li>`);
            statusMenu.push(`<li><button type="button" class="dropdown-item text-danger" onclick="removeAndDeleteUser('${escapeHtml(u.uuid)}')">Quitar del servidor y eliminar del panel</button></li>`);
            const renewItems = [7, 15, 30, 90, 365].map((d) =>
                `<li><button type="button" class="dropdown-item btn-quick-renew" data-uuid="${escapeHtml(u.uuid)}" data-days="${d}">+${d} días</button></li>`
            ).join('');
            const expiresDate = u.expires_at ? String(u.expires_at).slice(0, 10) : '';
            const dl = daysLeftBadge(expiresDate || u.expires_at);
            const mb = membershipBadge(u.on_server);
            const username = escapeHtml(u.display_name || u.username || '');
            const tg = normalizeTelegram(u.telegram_chat_id);
            const streams = Number(u.max_streams || 0);
            const serverBadge = u.server_name
                ? `${serviceBadgeHtml(u.server_type)} <span class="badge bg-light text-dark border text-truncate d-inline-block media-users-server-badge">${escapeHtml(u.server_name)}</span>`
                : (serviceBadgeHtml(u.server_type) || '<span class="text-muted">—</span>');

            return `<tr>
                <td class="small text-muted media-users-col-id">${Number(u.id || 0)}</td>
                <td class="min-w-0">
                    <a href="/media-users/${escapeHtml(u.uuid)}" class="fw-medium text-decoration-none text-truncate d-inline-block media-users-name">${username}</a>
                    <div class="small text-muted d-md-none text-truncate media-users-name">${escapeHtml(u.email || '-')}</div>
                </td>
                <td class="small d-none d-md-table-cell text-truncate media-users-email">${escapeHtml(u.email || '-')}</td>
                <td class="small d-none d-xl-table-cell">${serverBadge}</td>
                <td>
                    <div class="dropdown">
                        <button type="button" class="badge ${st.cls} border-0 dropdown-toggle media-users-status-toggle"
                                data-bs-toggle="dropdown" aria-expanded="false"
                                title="Actualizar / renovar o cambiar estado">
                            ${escapeHtml(st.label)}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-start shadow-sm">
                            <li><h6 class="dropdown-header">Actualizar / renovar</h6></li>
                            ${renewItems}
                            <li><hr class="dropdown-divider"></li>
                            <li><button type="button" class="dropdown-item" onclick="focusExpiresInput('${escapeHtml(u.uuid)}')">Cambiar fecha…</button></li>
                            <li><hr class="dropdown-divider"></li>
                            ${statusMenu.join('')}
                            <li><a class="dropdown-item" href="/media-users/${escapeHtml(u.uuid)}">Abrir ficha</a></li>
                            <li><a class="dropdown-item" href="/media-users/${escapeHtml(u.uuid)}/messages">Mensajes</a></li>
                        </ul>
                    </div>
                </td>
                <td class="d-none d-xl-table-cell">
                    <span class="badge text-truncate d-inline-block media-users-membership-badge ${mb.cls}" title="${escapeHtml(mb.label)}">${escapeHtml(mb.label)}</span>
                </td>
                <td class="d-none d-xl-table-cell small">${streams}</td>
                <td class="small">
                    <input type="date" class="form-control form-control-sm expires-input media-users-expires-input" data-uuid="${escapeHtml(u.uuid)}"
                           value="${escapeHtml(expiresDate)}" data-saved-value="${escapeHtml(expiresDate)}">
                </td>
                <td class="small text-nowrap"><span class="badge expires-days-badge ${dl.cls}" data-uuid="${escapeHtml(u.uuid)}">${escapeHtml(dl.label)}</span></td>
                <td class="small">
                    <input type="text" class="form-control form-control-sm telegram-input media-users-telegram-input" data-uuid="${escapeHtml(u.uuid)}"
                           value="${escapeHtml(tg)}" placeholder="Chat ID" title="Telegram Chat ID para enviar mensajes">
                    ${tg ? '<div class="small text-success mt-1">Vinculado</div>' : ''}
                </td>
                <td class="text-end">
                    <div class="btn-group btn-group-sm">
                        <a href="/media-users/${escapeHtml(u.uuid)}" class="btn btn-outline-secondary" title="Abrir ficha"><i class="bi bi-person"></i></a>
                        <a href="/media-users/${escapeHtml(u.uuid)}/messages" class="btn btn-outline-info" title="Historial mensajes"><i class="bi bi-chat-dots"></i></a>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" title="Actualizar / renovar">
                                <i class="bi bi-calendar-plus"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><h6 class="dropdown-header">Sumar días</h6></li>
                                ${renewItems}
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" onclick="focusExpiresInput('${escapeHtml(u.uuid)}')">Cambiar fecha…</button></li>
                            </ul>
                        </div>
                        ${actionBtns}
                        <button class="btn btn-outline-danger" onclick="removeAndDeleteUser('${escapeHtml(u.uuid)}')" title="Quitar del servidor y eliminar del panel"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
        initExpiresInputs(tbody);
    }

    const EXPIRES_SAVE_DELAY_MS = 1500;
    const expiresSaveTimers = new Map();

    function initExpiresInputs(root) {
        root.querySelectorAll('.expires-input').forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            if (input.dataset.savedValue === undefined) {
                input.dataset.savedValue = input.value;
            }
        });
    }

    function updateExpiresDaysBadge(uuid, expiresAt) {
        const badge = document.querySelector(`.expires-days-badge[data-uuid="${uuid}"]`);
        if (!badge) return;
        const dl = daysLeftBadge(expiresAt);
        badge.className = `badge expires-days-badge ${dl.cls}`;
        badge.textContent = dl.label;
    }

    function scheduleExpiresSave(input) {
        const uuid = input.dataset.uuid;
        if (!uuid) return;
        const existing = expiresSaveTimers.get(uuid);
        if (existing?.timer) {
            clearTimeout(existing.timer);
        }
        input.classList.add('is-saving-expires');
        input.classList.remove('is-saved-expires');
        input.title = 'Guardando en un momento…';
        const timer = setTimeout(() => {
            flushExpiresSave(input);
        }, EXPIRES_SAVE_DELAY_MS);
        expiresSaveTimers.set(uuid, { timer, input });
    }

    async function flushExpiresSave(input) {
        const uuid = input.dataset.uuid;
        if (!uuid) return;

        const pending = expiresSaveTimers.get(uuid);
        if (pending?.timer) {
            clearTimeout(pending.timer);
        }
        expiresSaveTimers.delete(uuid);

        const newVal = input.value;
        const savedVal = input.dataset.savedValue ?? '';
        if (newVal === savedVal) {
            input.classList.remove('is-saving-expires');
            input.title = '';
            return;
        }

        if (newVal === '' && savedVal !== '') {
            if (!confirm('¿Quitar la fecha de expiración de este usuario?')) {
                input.value = savedVal;
                input.classList.remove('is-saving-expires');
                input.title = '';
                return;
            }
        }

        input.classList.add('is-saving-expires');
        input.title = 'Guardando…';
        input.disabled = true;

        try {
            const res = await fetch(`/media-users/${uuid}/expires`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ expires_at: newVal }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                alert(data.message || 'Error al guardar fecha');
                input.value = savedVal;
                return;
            }

            const stored = (typeof data.expires_date === 'string' && data.expires_date)
                ? data.expires_date
                : newVal;
            input.value = stored;
            input.dataset.savedValue = stored;
            updateExpiresDaysBadge(uuid, stored);
            input.classList.remove('is-saving-expires');
            input.classList.add('is-saved-expires');
            input.title = data.reactivated ? 'Guardado · acceso reactivado' : 'Guardado';
            setTimeout(() => {
                input.classList.remove('is-saved-expires');
                if (input.title === 'Guardado' || input.title === 'Guardado · acceso reactivado') {
                    input.title = '';
                }
            }, 2500);
        } catch (err) {
            alert('Error de red al guardar fecha: ' + err.message);
            input.value = savedVal;
        } finally {
            input.disabled = false;
            input.classList.remove('is-saving-expires');
        }
    }

    initExpiresInputs(tbody);

    tbody.addEventListener('change', async (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;

        if (input.classList.contains('expires-input')) {
            scheduleExpiresSave(input);
            return;
        }

        if (input.classList.contains('telegram-input')) {
            const cleaned = normalizeTelegram(input.value);
            if (cleaned !== input.value) input.value = cleaned;
            const res = await fetch(`/media-users/${input.dataset.uuid}/telegram`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ telegram_chat_id: cleaned }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                alert(data.error || data.message || 'Error al guardar Telegram');
            }
        }
    });

    tbody.addEventListener('focusout', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.classList.contains('expires-input')) {
            return;
        }
        const uuid = input.dataset.uuid;
        if (!uuid) return;
        const pending = expiresSaveTimers.get(uuid);
        if (pending?.input === input) {
            flushExpiresSave(input);
        }
    });

    async function runSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) {
            tbody.innerHTML = initialHtml;
            initExpiresInputs(tbody);
            meta?.classList.add('d-none');
            if (countSummary) countSummary.innerHTML = initialCountHtml;
            return;
        }

        const params = listQueryParams();
        params.set('q', q);

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
