(() => {
    const bar = document.getElementById('mediaUsersBulkBar');
    const countEl = document.getElementById('mediaUsersBulkCount');
    const selectAll = document.getElementById('mediaUsersSelectAll');
    if (!bar || !countEl) return;

    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';

    function boxes() {
        return Array.from(document.querySelectorAll('.media-users-select'));
    }

    function selected() {
        return boxes().filter((el) => el.checked);
    }

    function selectedUuids() {
        return selected().map((el) => el.value).filter(Boolean);
    }

    function syncBar() {
        const sel = selected();
        countEl.textContent = String(sel.length);
        bar.classList.toggle('d-none', sel.length === 0);
        if (selectAll) {
            const all = boxes();
            selectAll.checked = all.length > 0 && sel.length === all.length;
            selectAll.indeterminate = sel.length > 0 && sel.length < all.length;
        }
    }

    function postBulk(action, confirmMsg) {
        const uuids = selectedUuids();
        if (!uuids.length) return;
        if (confirmMsg && !confirm(confirmMsg.replace('{n}', String(uuids.length)))) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/media-users/bulk-action';
        form.style.display = 'none';

        const token = csrf();
        const add = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };
        add('_token', token);
        add('action', action);
        uuids.forEach((uuid) => add('uuids[]', uuid));

        const params = new URLSearchParams(window.location.search);
        ['status', 'server_id', 'on_server', 'filter_empty', 'sort', 'dir', 'page'].forEach((key) => {
            const v = params.get(key);
            if (v !== null && v !== '') add(key, v);
        });

        document.body.appendChild(form);
        form.submit();
    }

    boxes().forEach((box) => box.addEventListener('change', syncBar));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            boxes().forEach((box) => {
                box.checked = selectAll.checked;
            });
            syncBar();
        });
    }

    document.getElementById('mediaUsersBulkClear')?.addEventListener('click', () => {
        boxes().forEach((box) => {
            box.checked = false;
        });
        syncBar();
    });

    document.getElementById('mediaUsersBulkSuspend')?.addEventListener('click', () => {
        postBulk('suspend', '¿Suspender {n} usuario(s)? Se intentará cortar el acceso en Plex/Jellyfin.');
    });
    document.getElementById('mediaUsersBulkExpire')?.addEventListener('click', () => {
        postBulk('expire', '¿Marcar caducados a {n} usuario(s)?');
    });
    document.getElementById('mediaUsersBulkSoftDelete')?.addEventListener('click', () => {
        postBulk('soft_delete', '¿Eliminar del panel {n} usuario(s)? No toca Plex/Jellyfin.');
    });

    syncBar();
})();
