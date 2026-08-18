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
    const renewDaysInput = document.getElementById('bulkRenewDays');

    function selectedBoxes() {
        return Array.from(document.querySelectorAll('.expiring-select:checked'));
    }

    function selectedUuids() {
        return selectedBoxes().map((el) => el.value).filter(Boolean);
    }

    function syncSectionSelectAll() {
        document.querySelectorAll('[data-expiring-section]').forEach((section) => {
            const master = section.querySelector('.expiring-select-all');
            if (!master) return;
            const boxes = section.querySelectorAll('.expiring-select');
            const checked = section.querySelectorAll('.expiring-select:checked');
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
            section.querySelectorAll('.expiring-select').forEach((box) => {
                box.checked = master.checked;
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

    syncBulkBar();
})();
