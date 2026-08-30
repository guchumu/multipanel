(function () {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    const filterDays = Number(window.EXPIRING_FILTER_DAYS || 15);
    const serverId = window.EXPIRING_SERVER_ID || null;

    async function post(url, body = {}) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Csrf-Token': csrf,
            },
            body: JSON.stringify(Object.assign({ _token: csrf }, body)),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.success === false) {
            throw new Error(data.error || data.message || 'Error');
        }
        return data;
    }

    async function postForm(url, fields) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.className = 'd-none';

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrf;
        form.appendChild(token);

        Object.entries(fields).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((v) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = String(v);
                    form.appendChild(input);
                });
                return;
            }
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = String(value ?? '');
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }

    document.querySelectorAll('.btn-quick-renew').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const uuid = btn.dataset.uuid;
            const days = Number(btn.dataset.days);
            if (!uuid || !days) return;
            if (!confirm(`¿Sumar ${days} días a este usuario?`)) return;

            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btn.disabled = true;

            try {
                const data = await post(`/media-users/${uuid}/add-days`, { days });
                alert(data.message || 'Días añadidos');
                location.reload();
            } catch (err) {
                alert(err.message);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        });
    });

    const bulkBar = document.getElementById('bulkMessageBar');
    const bulkCount = document.getElementById('bulkSelectedCount');
    const bulkInputs = document.getElementById('bulkUuidInputs');
    const clearBtn = document.getElementById('bulkClearSelection');
    const renewBtn = document.getElementById('bulkRenewBtn');
    const suspendBtn = document.getElementById('bulkSuspendBtn');
    const reengageBtn = document.getElementById('bulkReengageBtn');
    const renewDaysInput = document.getElementById('bulkRenewDays');

    function visibleRows() {
        return Array.from(document.querySelectorAll('.expiring-row')).filter((row) => !row.classList.contains('d-none'));
    }

    function selectedBoxes() {
        return visibleRows()
            .map((row) => row.querySelector('.expiring-select'))
            .filter((el) => el && el.checked);
    }

    function setBucket(bucket) {
        document.querySelectorAll('.urgency-card').forEach((card) => {
            card.classList.toggle('is-active', card.dataset.bucket === bucket);
        });
        document.querySelectorAll('.expiring-row').forEach((row) => {
            row.classList.toggle('d-none', row.dataset.bucket !== bucket);
            const box = row.querySelector('.expiring-select');
            if (box && row.dataset.bucket !== bucket) box.checked = false;
        });
        document.querySelectorAll('.expiring-col-last-msg').forEach((col) => {
            col.classList.toggle('d-none', bucket !== 'd180');
            col.classList.toggle('d-lg-table-cell', bucket === 'd180');
        });
        const title = document.getElementById('expiringListTitle');
        const titles = window.EXPIRING_BUCKET_TITLES || {};
        if (title && titles[bucket]) title.textContent = titles[bucket];
        syncBulkBar();
    }

    const initialBucket = window.EXPIRING_INITIAL_BUCKET;
    if (initialBucket) {
        setBucket(initialBucket);
    }

    document.getElementById('urgencyCards')?.addEventListener('click', (e) => {
        const card = e.target.closest('.urgency-card');
        if (!card) return;
        setBucket(card.dataset.bucket);
        const url = new URL(window.location.href);
        url.searchParams.set('bucket', card.dataset.bucket || '');
        window.history.replaceState({}, '', url);
    });

    function selectedUuids() {
        return selectedBoxes().map((el) => el.value).filter(Boolean);
    }

    function syncSectionSelectAll() {
        document.querySelectorAll('[data-expiring-section]').forEach((section) => {
            const master = section.querySelector('.expiring-select-all');
            if (!master) return;
            const boxes = visibleRows()
                .map((row) => row.querySelector('.expiring-select'))
                .filter(Boolean);
            const checked = boxes.filter((el) => el.checked);
            master.checked = boxes.length > 0 && checked.length === boxes.length;
            master.indeterminate = checked.length > 0 && checked.length < boxes.length;
        });
    }

    function syncBulkBar() {
        const selected = selectedBoxes();
        if (bulkCount) bulkCount.textContent = String(selected.length);
        if (bulkBar) bulkBar.classList.toggle('d-none', selected.length === 0);
        if (bulkInputs) {
            bulkInputs.innerHTML = selected
                .map((el) => `<input type="hidden" name="uuids[]" value="${el.value}">`)
                .join('');
        }
        syncSectionSelectAll();
    }

    document.querySelectorAll('.expiring-select').forEach((box) => {
        box.addEventListener('change', syncBulkBar);
    });

    document.querySelectorAll('.expiring-select-all').forEach((master) => {
        master.addEventListener('change', () => {
            const section = master.closest('[data-expiring-section]');
            if (!section) return;
            visibleRows().forEach((row) => {
                const box = row.querySelector('.expiring-select');
                if (box) box.checked = master.checked;
            });
            syncBulkBar();
        });
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            document.querySelectorAll('.expiring-select').forEach((box) => {
                box.checked = false;
            });
            syncBulkBar();
        });
    }

    if (renewBtn) {
        renewBtn.addEventListener('click', () => {
            const uuids = selectedUuids();
            if (!uuids.length) return;
            const days = Math.max(1, Number(renewDaysInput?.value || 30));
            if (!confirm(`¿Sumar ${days} días a ${uuids.length} usuario(s)?`)) return;
            const fields = {
                days,
                filter_days: filterDays,
                'uuids[]': uuids,
            };
            if (serverId) fields.server_id = serverId;
            postForm('/media-users/expiring/bulk-renew', fields);
        });
    }

    if (suspendBtn) {
        suspendBtn.addEventListener('click', () => {
            const uuids = selectedUuids();
            if (!uuids.length) return;
            if (!confirm(`¿Suspender ${uuids.length} usuario(s)? Se cortará el acceso en el servidor si es posible.`)) return;
            const fields = {
                filter_days: filterDays,
                'uuids[]': uuids,
            };
            if (serverId) fields.server_id = serverId;
            postForm('/media-users/expiring/bulk-suspend', fields);
        });
    }

    if (reengageBtn) {
        reengageBtn.addEventListener('click', () => {
            const uuids = selectedUuids();
            if (!uuids.length) return;
            if (!confirm(`¿Enviar el gancho de volver a ${uuids.length} usuario(s)? Se usará el texto guardado en Mensajes a usuarios.`)) return;
            const fields = {
                filter_days: filterDays,
                'uuids[]': uuids,
            };
            if (serverId) fields.server_id = serverId;
            postForm('/media-users/expiring/bulk-reengage', fields);
        });
    }

    document.querySelectorAll('.btn-reengage-invite').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const uuid = btn.dataset.uuid;
            if (!uuid) return;
            if (!confirm('¿Enviar la invitación a volver con el texto guardado?')) return;
            btn.classList.add('disabled');
            try {
                const data = await post(`/media-users/${uuid}/reengage`);
                alert(data.message || 'Enviado');
                if (data.sent) location.reload();
            } catch (err) {
                alert(err.message);
            } finally {
                btn.classList.remove('disabled');
            }
        });
    });

    document.querySelectorAll('.btn-reengage-trial').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const uuid = btn.dataset.uuid;
            const days = Number(btn.dataset.days || 3);
            if (!uuid) return;
            if (!confirm(`¿Abrir ${days} días de prueba y avisar al usuario? Se reactivará el acceso.`)) return;
            btn.classList.add('disabled');
            try {
                const data = await post(`/media-users/${uuid}/reengage-trial`);
                alert(data.message || 'Prueba abierta');
                location.reload();
            } catch (err) {
                alert(err.message);
            } finally {
                btn.classList.remove('disabled');
            }
        });
    });

    syncBulkBar();
})();
