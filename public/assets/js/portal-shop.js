(function () {
    const form = document.getElementById('ez-shop');
    if (!form) return;

    const discount = Number(form.dataset.discount || 40) / 100;
    const included = Number(form.dataset.included || 2);
    const buyerEmail = (form.dataset.buyerEmail || '').trim();
    const maxUsers = 6;
    const maxExtra = 4;

    const monthsInput = document.getElementById('ez-months');
    const usersInput = document.getElementById('ez-users');
    const extraInput = document.getElementById('ez-extra-streams');
    const usersN = document.getElementById('ez-users-n');
    const streamsN = document.getElementById('ez-streams-n');
    const emailsBox = document.getElementById('ez-emails');
    const ticketList = document.getElementById('ez-ticket-list');
    const totalEl = document.getElementById('ez-total');

    function money(n) {
        return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function selectedChip() {
        return form.querySelector('.ez-chip.is-on') || form.querySelector('.ez-chip');
    }

    function users() {
        return Math.max(1, Math.min(maxUsers, Number(usersInput.value || 1)));
    }

    function extraStreams() {
        return Math.max(0, Math.min(maxExtra, Number(extraInput.value || 0)));
    }

    function packPrice() {
        const chip = selectedChip();
        return Number(chip?.dataset.price || 0);
    }

    function renderEmails() {
        const n = users();
        const existing = Array.from(emailsBox.querySelectorAll('input')).map((el) => el.value);
        emailsBox.innerHTML = '';
        for (let i = 0; i < n; i++) {
            const wrap = document.createElement('div');
            wrap.className = 'mb-2';
            const label = document.createElement('label');
            label.className = 'form-label ez-mail-label';
            label.textContent = i === 0 ? 'Tu email' : 'Email de la persona ' + (i + 1);
            const input = document.createElement('input');
            input.type = 'email';
            input.name = 'emails[]';
            input.className = 'form-control form-control-lg';
            input.required = true;
            input.placeholder = 'alguien@email.com';
            input.autocomplete = 'email';
            if (i === 0 && buyerEmail) input.value = existing[0] || buyerEmail;
            else input.value = existing[i] || '';
            wrap.appendChild(label);
            wrap.appendChild(input);
            emailsBox.appendChild(wrap);
        }
    }

    function render() {
        const nUsers = users();
        const extra = extraStreams();
        const pack = packPrice();
        const extraUserUnit = Math.round(pack * (1 - discount) * 100) / 100;
        const extraStreamUnit = Math.round((pack / 2) * (1 - discount) * 100) / 100;
        const extraUsers = Math.max(0, nUsers - 1);
        const extraUsersPrice = Math.round(extraUsers * extraUserUnit * 100) / 100;
        const extraStreamsPrice = Math.round(extra * extraStreamUnit * 100) / 100;
        const total = Math.round((pack + extraUsersPrice + extraStreamsPrice) * 100) / 100;
        const chip = selectedChip();
        const label = chip?.querySelector('strong')?.textContent || 'Tiempo';
        const screens = nUsers * included + extra;

        usersN.textContent = String(nUsers);
        streamsN.textContent = String(extra);
        usersInput.value = String(nUsers);
        extraInput.value = String(extra);
        monthsInput.value = chip?.dataset.months || '1';

        const rows = [
            `${label} · 1 persona (incluye ${included} pantallas): ${money(pack)}`,
        ];
        if (extraUsers > 0) {
            rows.push(`${extraUsers} persona(s) extra (−${Math.round(discount * 100)}%): ${money(extraUsersPrice)}`);
        }
        if (extra > 0) {
            rows.push(`${extra} pantalla(s) extra (−${Math.round(discount * 100)}%): ${money(extraStreamsPrice)}`);
        }
        rows.push(`Pantallas en total: ${screens}`);

        ticketList.innerHTML = rows.map((r) => `<li>${r}</li>`).join('');
        totalEl.textContent = money(total);
    }

    form.querySelectorAll('.ez-chip').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.querySelectorAll('.ez-chip').forEach((b) => b.classList.remove('is-on'));
            btn.classList.add('is-on');
            render();
        });
    });

    document.getElementById('ez-users-minus')?.addEventListener('click', () => {
        usersInput.value = String(Math.max(1, users() - 1));
        renderEmails();
        render();
    });
    document.getElementById('ez-users-plus')?.addEventListener('click', () => {
        usersInput.value = String(Math.min(maxUsers, users() + 1));
        renderEmails();
        render();
    });
    document.getElementById('ez-streams-minus')?.addEventListener('click', () => {
        extraInput.value = String(Math.max(0, extraStreams() - 1));
        render();
    });
    document.getElementById('ez-streams-plus')?.addEventListener('click', () => {
        extraInput.value = String(Math.min(maxExtra, extraStreams() + 1));
        render();
    });

    form.addEventListener('submit', (e) => {
        const n = users();
        const filled = Array.from(emailsBox.querySelectorAll('input')).filter((el) => el.value.trim());
        if (filled.length < n) {
            e.preventDefault();
            alert('Escribe el email de cada persona.');
        }
    });

    renderEmails();
    render();
})();
