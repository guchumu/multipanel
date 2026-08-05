(function () {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

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

    function selectedBoxes() {
        return Array.from(document.querySelectorAll('.expiring-select:checked'));
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

    syncBulkBar();
})();
