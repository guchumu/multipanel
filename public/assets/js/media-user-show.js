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
        const token = document.querySelector('meta[name=csrf-token]')?.content || csrf || '';
        if (!token) {
            return { success: false, message: 'No hay token CSRF. Recarga la página (F5).', __httpOk: false, __status: 0 };
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

    function toast(msg) {
        const text = (typeof msg === 'string' && msg.trim() !== '')
            ? msg
            : 'Operación completada.';
        alert(text);
    }

    function responseMessage(data, fallbackOk, fallbackFail) {
        if (typeof data?.message === 'string' && data.message.trim() !== '') {
            return data.message;
        }
        if (typeof data?.error === 'string' && data.error.trim() !== '') {
            return data.error;
        }
        return data?.success === false ? fallbackFail : fallbackOk;
    }

    document.getElementById('telegramChatId')?.addEventListener('change', async (e) => {
        try {
            const data = await post(`/media-users/${uuid}/telegram`, { telegram_chat_id: e.target.value });
            if (data.success === false) throw new Error(data.message || 'Error');
            toast('Telegram guardado');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('whatsappPhone')?.addEventListener('change', async (e) => {
        try {
            const data = await post(`/media-users/${uuid}/whatsapp`, { whatsapp_phone: e.target.value });
            if (data.success === false) throw new Error(data.message || 'Error');
            whatsappPhone = data.whatsapp_phone || '';
            e.target.value = whatsappPhone;
            toast('WhatsApp guardado');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('expiresAt')?.addEventListener('change', async (e) => {
        try {
            const data = await post(`/media-users/${uuid}/expires`, { expires_at: e.target.value });
            if (data.success === false) throw new Error(data.message || 'Error');
            toast('Fecha guardada');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('userNotes')?.addEventListener('change', async (e) => {
        try {
            const data = await post(`/media-users/${uuid}/notes`, { notes: e.target.value });
            if (data.success === false) throw new Error(data.message || 'Error');
            toast('Notas guardadas');
        } catch (err) {
            toast(err.message);
        }
    });

    document.querySelectorAll('.btn-add-days').forEach((btn) => {
        btn.addEventListener('click', async () => {
            try {
                const data = await post(`/media-users/${uuid}/add-days`, { days: Number(btn.dataset.days) });
                if (data.success === false) throw new Error(data.message || 'Error');
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
            const streamsRaw = (document.getElementById('editMaxStreams')?.value || '').trim();
            const data = await post(`/media-users/${uuid}/profile`, {
                username: document.getElementById('editUsername')?.value || '',
                display_name: document.getElementById('editDisplayName')?.value || '',
                email: document.getElementById('editEmail')?.value || '',
                // Vacío = usar default del tenant
                max_streams: streamsRaw === '' ? '' : Number(streamsRaw),
                max_devices: Number(document.getElementById('editMaxDevices')?.value || 5),
            });
            if (data.success === false) throw new Error(data.message || 'Error');
            toast(data.message || 'Datos guardados');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnActivate')?.addEventListener('click', async () => {
        try {
            const data = await post(`/media-users/${uuid}/activate`);
            toast(responseMessage(data, 'Usuario activado', 'No se pudo activar del todo'));
            location.reload();
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnSuspend')?.addEventListener('click', async () => {
        if (!confirm('¿Suspender este usuario? Se cortará el acceso a la biblioteca y se terminarán las sesiones activas.')) return;
        try {
            const data = await post(`/media-users/${uuid}/suspend`);
            toast(responseMessage(
                data,
                'Usuario suspendido. Acceso cortado.',
                'Marcado suspendido en el panel, pero NO se cortó el acceso en el servidor.'
            ));
            location.reload();
        } catch (err) {
            toast(err.message || 'Error al suspender');
        }
    });

    document.getElementById('btnRemoveServer')?.addEventListener('click', async () => {
        if (!confirm('¿Eliminar al usuario del servidor Plex/Jellyfin? Esta acción no se puede deshacer fácilmente.')) return;
        try {
            const data = await post(`/media-users/${uuid}/remove-server`);
            if (data.success === false) throw new Error(data.message || 'Error');
            toast(data.message);
            location.reload();
        } catch (err) {
            toast(err.message);
        }
    });

    async function forceMembershipSync(btn) {
        if (!btn) return;
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Comprobando…';
        const resultBox = document.getElementById('membershipResult');
        const resultLabel = document.getElementById('membershipResultLabel');
        const resultHint = document.getElementById('membershipResultHint');
        const badge = document.getElementById('membershipBadge');
        try {
            const data = await post(`/media-users/${uuid}/sync-membership`);
            const onServer = data.on_server;
            let label = 'Sin sync';
            let cls = 'bg-light text-dark border';
            let alertCls = 'alert-secondary';
            let hint = 'No se pudo determinar el estado.';
            if (data.success !== false && data.__httpOk !== false) {
                if (onServer === true) {
                    label = 'En biblioteca';
                    cls = 'bg-success';
                    alertCls = 'alert-success';
                    hint = 'Aparece en la lista de usuarios del servidor.';
                } else if (onServer === false) {
                    label = 'No está en el servidor';
                    cls = 'bg-danger';
                    alertCls = 'alert-danger';
                    hint = 'Tenía external_id en el panel pero ya no aparece en Plex/Jellyfin. Puedes eliminarlo del panel.';
                }
            }
            if (badge) {
                badge.className = 'badge fs-5 px-3 py-2 ' + cls;
                badge.textContent = label;
            }
            if (resultBox) {
                resultBox.className = 'alert mt-3 mb-0 ' + alertCls;
            }
            if (resultLabel) resultLabel.textContent = label + '.';
            if (resultHint) resultHint.textContent = (typeof data.message === 'string' && data.message) ? data.message : hint;
            toast(responseMessage(
                data,
                onServer === true ? 'En biblioteca' : (onServer === false ? 'No está en el servidor' : 'Sincronización completada.'),
                'No se pudo sincronizar.'
            ));
            if (data.success !== false && data.__httpOk !== false) {
                setTimeout(() => location.reload(), 900);
            } else {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        } catch (err) {
            toast(err.message || 'Error de red');
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }

    document.getElementById('btnSyncMembership')?.addEventListener('click', function () {
        forceMembershipSync(this);
    });
    document.getElementById('btnSyncMembershipControl')?.addEventListener('click', function () {
        forceMembershipSync(this);
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
            if (data.success === false || !data.__httpOk) {
                throw new Error(data.message || data.error || 'No se pudo generar el enlace de pago');
            }
            document.getElementById('stripeLink').value = data.checkout_url || '';
            document.getElementById('stripeLinkBox').classList.remove('d-none');
            toast(data.message || 'Enlace generado');
        } catch (err) {
            toast(err.message || 'Error al generar el pago con Stripe');
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
            if (data.success === false || !data.__httpOk) throw new Error(data.error || data.message || 'Error');
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

    document.getElementById('btnPortalLinkCreate')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnPortalLinkCreate');
        btn.disabled = true;
        try {
            const data = await post(`/media-users/${uuid}/portal-link`, {
                purpose: document.getElementById('portalLinkPurpose')?.value || 'home',
                days: parseInt(document.getElementById('portalLinkDays')?.value || '30', 10),
            });
            if (data.success === false || !data.__httpOk) {
                throw new Error(data.message || data.error || 'No se pudo generar el enlace');
            }
            const box = document.getElementById('portalLinkBox');
            const input = document.getElementById('portalLinkUrl');
            if (input) input.value = data.url || '';
            box?.classList.remove('d-none');
            const status = document.getElementById('portalLinkStatus');
            if (status) {
                const until = (data.expires_at || '').toString().slice(0, 10);
                status.className = 'small mb-2 text-success';
                status.textContent = until
                    ? `Enlace activo hasta ${until}. Cópialo ahora.`
                    : 'Enlace activo. Cópialo ahora.';
            }
            toast(data.message || 'Enlace generado');
        } catch (err) {
            toast(err.message);
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('btnPortalLinkRevoke')?.addEventListener('click', async () => {
        if (!confirm('¿Cancelar el enlace activo? El cliente ya no podrá entrar con él.')) return;
        try {
            const data = await post(`/media-users/${uuid}/portal-link/revoke`);
            if (data.success === false || !data.__httpOk) throw new Error(data.message || 'Error');
            document.getElementById('portalLinkBox')?.classList.add('d-none');
            const input = document.getElementById('portalLinkUrl');
            if (input) input.value = '';
            const status = document.getElementById('portalLinkStatus');
            if (status) {
                status.className = 'small mb-2 text-muted';
                status.textContent = 'No hay enlace activo.';
            }
            toast(data.message || 'Enlace cancelado');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnCopyPortalLink')?.addEventListener('click', () => {
        const input = document.getElementById('portalLinkUrl');
        if (!input?.value) return;
        navigator.clipboard?.writeText(input.value).then(() => toast('Enlace copiado')).catch(() => {
            input.select();
            document.execCommand('copy');
            toast('Enlace copiado');
        });
    });

    document.getElementById('btnSendPortalTelegram')?.addEventListener('click', async () => {
        const url = document.getElementById('portalLinkUrl')?.value || '';
        if (!url) {
            toast('Genera primero el enlace.');
            return;
        }
        try {
            const data = await post(`/media-users/${uuid}/portal-link/send`, { url });
            if (data.success === false || !data.__httpOk) throw new Error(data.message || 'Error');
            toast(data.message || 'Enviado');
        } catch (err) {
            toast(err.message);
        }
    });

    document.getElementById('btnSendPortalWhatsapp')?.addEventListener('click', () => {
        const url = document.getElementById('portalLinkUrl')?.value || '';
        if (!url) {
            toast('Genera primero el enlace.');
            return;
        }
        window.open(waLink(`Hola! Entra a tu cuenta (sin contraseña):\n${url}`), '_blank');
    });

    document.getElementById('btnSendMsg')?.addEventListener('click', async () => {
        try {
            const data = await post(`/media-users/${uuid}/send-message`, {
                title: document.getElementById('msgTitle')?.value || 'Aviso',
                body: document.getElementById('msgBody')?.value || '',
            });
            if (data.success === false || !data.__httpOk) throw new Error(data.error || data.message || 'Error');
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

    function copyText(value, okMsg) {
        const text = (value || '').toString();
        if (!text) {
            toast('No hay nada que copiar.');
            return;
        }
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text).then(() => toast(okMsg)).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                toast(okMsg);
            });
            return;
        }
        toast(okMsg);
    }

    function applyJellyfinCredentials(username, password, text) {
        const userInput = document.getElementById('jellyfinUsername');
        const passInput = document.getElementById('jellyfinPassword');
        const textArea = document.getElementById('jellyfinCredentialsText');
        if (userInput && username) userInput.value = username;
        if (passInput) {
            passInput.value = password || '';
            passInput.type = 'password';
        }
        if (textArea && typeof text === 'string') textArea.value = text;
        const hasPass = !!(password && String(password).length);
        ['btnRevealJellyfinPassword', 'btnCopyJellyfinPassword', 'btnCopyJellyfinCredentials'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.disabled = !hasPass;
        });
    }

    document.getElementById('btnRevealJellyfinPassword')?.addEventListener('click', () => {
        const input = document.getElementById('jellyfinPassword');
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    document.getElementById('btnCopyJellyfinUser')?.addEventListener('click', () => {
        copyText(document.getElementById('jellyfinUsername')?.value || '', 'Usuario copiado');
    });

    document.getElementById('btnCopyJellyfinPassword')?.addEventListener('click', () => {
        copyText(document.getElementById('jellyfinPassword')?.value || '', 'Contraseña copiada');
    });

    document.getElementById('btnCopyJellyfinCredentials')?.addEventListener('click', () => {
        copyText(document.getElementById('jellyfinCredentialsText')?.value || '', 'Mensaje copiado');
    });

    document.getElementById('btnRegenJellyfinPassword')?.addEventListener('click', async () => {
        if (!confirm('¿Regenerar la contraseña en Jellyfin? La anterior dejará de funcionar.')) return;
        const btn = document.getElementById('btnRegenJellyfinPassword');
        const original = btn?.innerHTML;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Regenerando…';
        }
        try {
            const data = await post(`/media-users/${uuid}/jellyfin-password/regenerate`);
            if (data.success === false || !data.__httpOk) {
                throw new Error(data.message || data.error || 'Error');
            }
            applyJellyfinCredentials(data.username, data.password, data.credentials_text || '');
            toast(data.message || 'Contraseña regenerada');
        } catch (err) {
            toast(err.message || 'No se pudo regenerar');
        } finally {
            if (btn && original) {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }
    });

    document.getElementById('btnSendJellyfinTelegram')?.addEventListener('click', async () => {
        try {
            const data = await post(`/media-users/${uuid}/jellyfin-credentials/send`);
            if (data.success === false || !data.__httpOk) {
                throw new Error(data.message || data.error || 'Error');
            }
            if (data.credentials_text) {
                applyJellyfinCredentials(data.username, data.password, data.credentials_text);
            }
            toast(data.message || 'Credenciales enviadas por Telegram');
        } catch (err) {
            toast(err.message || 'No se pudo enviar');
        }
    });

    document.getElementById('btnSendJellyfinWhatsapp')?.addEventListener('click', () => {
        const text = document.getElementById('jellyfinCredentialsText')?.value || '';
        if (!text.trim()) {
            toast('No hay credenciales para enviar. Regenera la contraseña primero.');
            return;
        }
        window.open(waLink(text), '_blank');
    });

    document.addEventListener('click', async function (e) {
        const retryBtn = e.target.closest('.btn-retry-msg');
        if (retryBtn) {
            const msgId = Number(retryBtn.dataset.msgId || 0);
            if (!msgId || !uuid) return;
            if (!confirm('¿Reintentar el envío de este aviso por Telegram?')) return;
            retryBtn.disabled = true;
            try {
                const data = await post(`/media-users/${uuid}/messages/${msgId}/retry`);
                if (data.success === false || !data.__httpOk) {
                    throw new Error(data.message || data.error || 'No se pudo reenviar');
                }
                toast(data.message || 'Aviso reenviado');
                location.reload();
            } catch (err) {
                toast(err.message || 'Error al reintentar');
                retryBtn.disabled = false;
            }
            return;
        }

        const btn = e.target.closest('.btn-kill-session');
        if (!btn) return;
        if (!confirm('¿Detener esta reproducción?')) return;
        btn.disabled = true;
        try {
            const data = await post('/activity/kill', {
                server_id: Number(btn.dataset.serverId),
                session_id: btn.dataset.sessionId,
            });
            if (data.success === false || !data.__httpOk) throw new Error(data.error || data.message || 'Error');
            toast(data.message || 'Reproducción detenida');
            btn.closest('.session-card')?.closest('.session-col')?.remove();
        } catch (err) {
            toast(err.message);
            btn.disabled = false;
        }
    });

    document.querySelectorAll('.btn-ep-kind').forEach((btn) => {
        btn.addEventListener('click', async () => {
            try {
                const data = await post(`/media-users/${uuid}/endpoints/${btn.dataset.epId}/kind`, {
                    kind: btn.dataset.kind,
                });
                if (data.success === false || !data.__httpOk) throw new Error(data.message || 'Error');
                location.reload();
            } catch (err) {
                toast(err.message);
            }
        });
    });
})();
