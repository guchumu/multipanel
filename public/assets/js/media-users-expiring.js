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

    const selectAll = document.getElementById('selectAllExpiring');
    const bulkBar = document.getElementById('bulkMessageBar');
    const bulkCount = document.getElementById('bulkSelectedCount');
    const bulkInputs = document.getElementById('bulkUuidInputs');
    const clearBtn = document.getElementById('bulkClearSelection');

    function selectedBoxes() {
        return Array.from(document.querySelectorAll('.expiring-select:checked'));
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
        if (selectAll) {
            const all = document.querySelectorAll('.expiring-select');
            selectAll.checked = all.length > 0 && selected.length === all.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
        }
    }

    document.querySelectorAll('.expiring-select').forEach((box) => {
        box.addEventListener('change', syncBulkBar);
    });

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            document.querySelectorAll('.expiring-select').forEach((box) => {
                box.checked = selectAll.checked;
            });
            syncBulkBar();
        });
    }

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
