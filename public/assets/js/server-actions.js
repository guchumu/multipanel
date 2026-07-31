/**
 * Loading states and feedback for server sync / test / debug buttons.
 */
(function () {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

    function setBusy(btn, busy, label) {
        if (!btn) return;
        btn.disabled = busy;
        const icon = btn.querySelector('i');
        if (busy) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status"></span>${label}`;
        } else if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
            delete btn.dataset.originalHtml;
        }
    }

    function showStatus(message, type) {
        let box = document.getElementById('server-action-status');
        if (!box) {
            box = document.createElement('div');
            box.id = 'server-action-status';
            box.className = 'alert alert-info py-2 small mb-3';
            const anchor = document.querySelector('.app-content') || document.body;
            anchor.prepend(box);
        }
        box.className = `alert alert-${type || 'info'} py-2 small mb-3`;
        box.textContent = message;
        box.classList.remove('d-none');
    }

    async function postAction(url) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        });
        return res.json();
    }

    async function getAction(url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        return res.json();
    }

    document.querySelectorAll('.btn-sync').forEach(btn => {
        btn.addEventListener('click', async function () {
            const uuid = this.dataset.uuid;
            setBusy(this, true, 'Sincronizando…');
            showStatus('Sincronizando servidor (usuarios, bibliotecas, sesiones)…', 'info');
            try {
                const data = await postAction(`/servers/${uuid}/sync`);
                showStatus(data.message || 'Sync completado.', data.success ? 'success' : 'danger');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                showStatus('Error de red al sincronizar.', 'danger');
                setBusy(this, false);
            }
        });
    });

    document.querySelectorAll('.btn-test').forEach(btn => {
        btn.addEventListener('click', async function () {
            const uuid = this.dataset.uuid;
            setBusy(this, true, 'Probando…');
            showStatus('Comprobando conexión y streams activos…', 'info');
            try {
                const data = await postAction(`/servers/${uuid}/test`);
                showStatus(data.message || 'Test completado.', data.connected ? 'success' : 'warning');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                showStatus('Error de red en el test.', 'danger');
                setBusy(this, false);
            }
        });
    });

    document.querySelectorAll('.btn-debug').forEach(btn => {
        btn.addEventListener('click', async function () {
            const uuid = this.dataset.uuid;
            setBusy(this, true, 'Analizando…');
            showStatus('Ejecutando debug completo (URLs, plex.tv, conexión)…', 'info');
            try {
                const data = await getAction(`/servers/${uuid}/debug`);
                showStatus(data.message || (data.success ? 'Debug OK' : 'Debug fallido'), data.success ? 'success' : 'danger');
                setTimeout(() => location.reload(), 1200);
            } catch (e) {
                showStatus('Error de red en debug.', 'danger');
                setBusy(this, false);
            }
        });
    });

    document.querySelectorAll('.btn-default-star').forEach(btn => {
        btn.addEventListener('click', async function () {
            const uuid = this.dataset.uuid;
            const type = (this.dataset.type || 'plex').toUpperCase();
            showStatus(`Marcando servidor ${type} por defecto…`, 'info');
            try {
                const data = await postAction(`/servers/${uuid}/default`);
                showStatus(data.message || 'Actualizado.', data.success ? 'success' : 'danger');
                setTimeout(() => location.reload(), 600);
            } catch (e) {
                showStatus('Error al marcar predeterminado.', 'danger');
            }
        });
    });
})();
