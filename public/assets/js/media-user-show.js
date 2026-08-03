(function () {
    const uuid = window.MEDIA_USER_UUID;
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

    async function post(url, body = {}) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.success === false) {
            throw new Error(data.error || data.message || 'Error');
        }
        return data;
    }

    function toast(msg) {
        alert(msg);
    }

    document.getElementById('telegramChatId')?.addEventListener('change', async (e) => {
        try {
            await post(`/media-users/${uuid}/telegram`, { telegram_chat_id: e.target.value });
            toast('Telegram guardado');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('expiresAt')?.addEventListener('change', async (e) => {
        try {
            await post(`/media-users/${uuid}/expires`, { expires_at: e.target.value });
            toast('Fecha guardada');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('userNotes')?.addEventListener('change', async (e) => {
        try {
            await post(`/media-users/${uuid}/notes`, { notes: e.target.value });
            toast('Notas guardadas');
        } catch (err) {
            toast(err.message);
        }
    });

    document.querySelectorAll('.btn-add-days').forEach((btn) => {
        btn.addEventListener('click', async () => {
            try {
                const data = await post(`/media-users/${uuid}/add-days`, { days: Number(btn.dataset.days) });
                if (data.expires_at) {
                    document.getElementById('expiresAt').value = data.expires_at.substring(0, 10);
                }
                toast(data.message || 'Días añadidos');
                setTimeout(() => location.reload(), 600);
            } catch (err) {
                toast(err.message);
            }
        });
    });

    document.getElementById('btnSaveProfile')?.addEventListener('click', async () => {
        try {
            const data = await post(`/media-users/${uuid}/profile`, {
                username: document.getElementById('editUsername')?.value || '',
                display_name: document.getElementById('editDisplayName')?.value || '',
                email: document.getElementById('editEmail')?.value || '',
                max_streams: Number(document.getElementById('editMaxStreams')?.value || 1),
                max_devices: Number(document.getElementById('editMaxDevices')?.value || 5),
            });
            toast(data.message || 'Datos guardados');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnActivate')?.addEventListener('click', async () => {
        try {
            const data = await post(`/media-users/${uuid}/activate`);
            toast(data.message || 'Usuario activado');
            location.reload();
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnSuspend')?.addEventListener('click', async () => {
        if (!confirm('¿Suspender este usuario? Se cortará el acceso a la biblioteca.')) return;
        try {
            const data = await post(`/media-users/${uuid}/suspend`);
            toast(data.message || 'Usuario suspendido');
            location.reload();
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnRemoveServer')?.addEventListener('click', async () => {
        if (!confirm('¿Eliminar al usuario del servidor Plex/Jellyfin? Esta acción no se puede deshacer fácilmente.')) return;
        try {
            const data = await post(`/media-users/${uuid}/remove-server`);
            toast(data.message);
            location.reload();
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnSendMsg')?.addEventListener('click', async () => {
        try {
            const data = await post(`/media-users/${uuid}/send-message`, {
                title: document.getElementById('msgTitle')?.value || 'Aviso',
                body: document.getElementById('msgBody')?.value || '',
            });
            toast(data.message || 'Enviado');
            location.reload();
        } catch (err) {
            toast(err.message);
        }
    });
})();
