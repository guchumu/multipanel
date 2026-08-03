(function () {
    const uuid = window.MEDIA_USER_UUID;
    let whatsappPhone = window.MEDIA_USER_WHATSAPP || '';
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

    function waLink(text) {
        const phone = (whatsappPhone || '').replace(/\D+/g, '');
        const base = phone ? `https://wa.me/${phone}` : 'https://wa.me/';
        return `${base}?text=${encodeURIComponent(text)}`;
    }

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

    document.getElementById('whatsappPhone')?.addEventListener('change', async (e) => {
        try {
            const data = await post(`/media-users/${uuid}/whatsapp`, { whatsapp_phone: e.target.value });
            whatsappPhone = data.whatsapp_phone || '';
            e.target.value = whatsappPhone;
            toast('WhatsApp guardado');
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

    document.getElementById('stripePreset')?.addEventListener('change', (e) => {
        const opt = e.target.selectedOptions[0];
        if (!opt || opt.value === '') return;
        document.getElementById('stripeAmount').value = opt.dataset.price;
        document.getElementById('stripeDays').value = opt.dataset.days;
    });

    document.getElementById('btnStripeCheckout')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnStripeCheckout');
        const amount = Number(document.getElementById('stripeAmount')?.value || 0);
        const days = Number(document.getElementById('stripeDays')?.value || 0);
        if (amount <= 0 || days <= 0) {
            toast('Introduce un importe y unos días válidos.');
            return;
        }

        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            const data = await post(`/media-users/${uuid}/stripe-checkout`, { amount, days, currency: 'EUR' });
            document.getElementById('stripeLink').value = data.checkout_url || '';
            document.getElementById('stripeLinkBox').classList.remove('d-none');
            toast(data.message || 'Enlace generado');
        } catch (err) {
            toast(err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });

    document.getElementById('btnCopyStripeLink')?.addEventListener('click', () => {
        const input = document.getElementById('stripeLink');
        if (!input?.value) return;
        navigator.clipboard?.writeText(input.value).then(() => toast('Enlace copiado')).catch(() => {
            input.select();
            document.execCommand('copy');
            toast('Enlace copiado');
        });
    });

    document.getElementById('btnSendStripeLink')?.addEventListener('click', async () => {
        const link = document.getElementById('stripeLink')?.value || '';
        if (!link) {
            toast('Genera primero el enlace de pago.');
            return;
        }
        try {
            const data = await post(`/media-users/${uuid}/send-message`, {
                title: 'Pago pendiente',
                body: `Para renovar tu acceso, completa el pago aquí:\n${link}`,
            });
            toast(data.message || 'Enviado');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnSendStripeWhatsapp')?.addEventListener('click', () => {
        const link = document.getElementById('stripeLink')?.value || '';
        if (!link) {
            toast('Genera primero el enlace de pago.');
            return;
        }
        window.open(waLink(`Hola! Para renovar tu acceso, completa el pago aquí:\n${link}`), '_blank');
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

    document.getElementById('btnSendMsgWhatsapp')?.addEventListener('click', () => {
        const body = document.getElementById('msgBody')?.value || '';
        if (!body.trim()) {
            toast('Escribe el mensaje.');
            return;
        }
        window.open(waLink(body), '_blank');
    });

    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.btn-kill-session');
        if (!btn) return;
        if (!confirm('¿Detener esta reproducción?')) return;
        btn.disabled = true;
        try {
            const data = await post('/activity/kill', {
                server_id: Number(btn.dataset.serverId),
                session_id: btn.dataset.sessionId,
            });
            toast(data.message || 'Reproducción detenida');
            btn.closest('.session-card')?.closest('.col-sm-6')?.remove();
        } catch (err) {
            toast(err.message);
            btn.disabled = false;
        }
    });
})();
