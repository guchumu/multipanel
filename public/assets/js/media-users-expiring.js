(function () {
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
})();
