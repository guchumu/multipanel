/**
 * Loading states and feedback for server sync / test / debug / default-star buttons.
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

    function csrfToken() {
        return document.querySelector('meta[name=csrf-token]')?.content || csrf || '';
    }

    async function postJson(url, body = {}) {
        const token = csrfToken();
        if (!token) {
            return {
                success: false,
                message: 'No hay token CSRF. Recarga la página (F5).',
                __httpOk: false,
                __status: 0,
            };
        }
        const payload = { ...body, _token: token };
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Csrf-Token': token,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        data.__httpOk = res.ok;
        data.__status = res.status;
        return data;
    }

    async function getAction(url) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        data.__httpOk = res.ok;
        data.__status = res.status;
        return data;
    }

    function responseMessage(data, fallbackOk, fallbackFail) {
        if (typeof data?.message === 'string' && data.message.trim() !== '') {
            return data.message;
        }
        if (typeof data?.error === 'string' && data.error.trim() !== '') {
            return data.error;
        }
        return data?.success === false || data?.__httpOk === false ? fallbackFail : fallbackOk;
    }

    document.querySelectorAll('.btn-sync').forEach(btn => {
        btn.addEventListener('click', async function () {
            const uuid = this.dataset.uuid;
            setBusy(this, true, 'Sincronizando…');
            showStatus('Sincronizando servidor (usuarios, bibliotecas, sesiones)…', 'info');
            try {
                const data = await postJson(`/servers/${uuid}/sync`);
                if (!data.__httpOk || data.success === false) {
                    showStatus(responseMessage(data, '', 'Error al sincronizar.'), 'danger');
                    setBusy(this, false);
                    return;
                }
                showStatus(responseMessage(data, 'Sync completado.', 'Error al sincronizar.'), 'success');
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
                const data = await postJson(`/servers/${uuid}/test`);
                if (!data.__httpOk) {
                    showStatus(responseMessage(data, '', 'Error en el test.'), 'danger');
                    setBusy(this, false);
                    return;
                }
                showStatus(
                    responseMessage(data, 'Test completado.', 'Test fallido.'),
                    data.connected ? 'success' : 'warning'
                );
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
                if (!data.__httpOk || data.success === false) {
                    showStatus(responseMessage(data, '', 'Debug fallido'), 'danger');
                    setBusy(this, false);
                    return;
                }
                showStatus(responseMessage(data, 'Debug OK', 'Debug fallido'), 'success');
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
            this.disabled = true;
            showStatus(`Marcando servidor ${type} por defecto…`, 'info');
            try {
                const data = await postJson(`/servers/${uuid}/default`);
                if (!data.__httpOk || data.success === false) {
                    showStatus(
                        responseMessage(data, '', 'No se pudo marcar como predeterminado.'),
                        'danger'
                    );
                    this.disabled = false;
                    return;
                }
                showStatus(
                    responseMessage(data, 'Servidor marcado como predeterminado.', ''),
                    'success'
                );
                setTimeout(() => location.reload(), 600);
            } catch (e) {
                showStatus('Error de red al marcar predeterminado.', 'danger');
                this.disabled = false;
            }
        });
    });
})();
