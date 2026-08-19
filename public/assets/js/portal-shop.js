(function () {
    const form = document.getElementById('ez-shop');
    if (!form) return;

    const included = Number(form.dataset.included || 2);
    const extraAccount = Number(form.dataset.extraAccount || 50);
    const extraStreamMonth = Number(form.dataset.extraStreamMonth || 4);
    const buyerEmail = (form.dataset.buyerEmail || '').trim();
    const maxAccounts = 6;
    const maxStreams = 6;

    const monthsInput = document.getElementById('ez-months');
    const box = document.getElementById('ez-accounts');
    const ticketList = document.getElementById('ez-ticket-list');
    const totalEl = document.getElementById('ez-total');

    let accounts = [{ email: buyerEmail, streams: included }];

    function money(n) {
        return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function selectedChip() {
        return form.querySelector('.ez-chip.is-on') || form.querySelector('.ez-chip');
    }

    function packPrice() {
        return Number(selectedChip()?.dataset.price || 0);
    }

    function periodMonths() {
        return Math.max(1, Number(selectedChip()?.dataset.months || 1));
    }

    function renderAccounts() {
        const emails = Array.from(box.querySelectorAll('input[name="emails[]"]')).map((el) => el.value);
        box.innerHTML = '';
        accounts.forEach((acc, i) => {
            if (emails[i] !== undefined) acc.email = emails[i];
            const row = document.createElement('div');
            row.className = 'ez-account-row';

            const mailWrap = document.createElement('div');
            mailWrap.className = 'ez-account-email';
            const mailLabel = document.createElement('label');
            mailLabel.className = 'form-label ez-mail-label';
            mailLabel.textContent = i === 0 ? 'Email' : 'Email cuenta extra';
            const mail = document.createElement('input');
            mail.type = 'email';
            mail.name = 'emails[]';
            mail.className = 'form-control';
            mail.required = true;
            mail.placeholder = 'cuenta@email.com';
            mail.autocomplete = i === 0 ? 'email' : 'off';
            mail.value = acc.email || (i === 0 ? buyerEmail : '');
            mail.addEventListener('input', () => { accounts[i].email = mail.value; });
            mailWrap.appendChild(mailLabel);
            mailWrap.appendChild(mail);

            const visWrap = document.createElement('div');
            visWrap.className = 'ez-account-vis';
            const visLabel = document.createElement('div');
            visLabel.className = 'form-label ez-mail-label';
            visLabel.textContent = 'Visualizaciones';
            const stepper = document.createElement('div');
            stepper.className = 'ez-mini-step';
            const minus = document.createElement('button');
            minus.type = 'button';
            minus.className = 'ez-pm ez-pm-sm';
            minus.setAttribute('aria-label', 'Menos visionados');
            minus.textContent = '−';
            const count = document.createElement('strong');
            count.textContent = String(acc.streams);
            const plus = document.createElement('button');
            plus.type = 'button';
            plus.className = 'ez-pm ez-pm-sm';
            plus.setAttribute('aria-label', 'Más visionados');
            plus.textContent = '+';
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'streams[]';
            hidden.value = String(acc.streams);
            const hint = document.createElement('span');
            hint.className = 'ez-vis-hint';
            hint.textContent = included + ' incluidas · extra ' + money(extraStreamMonth) + '/mes';

            minus.addEventListener('click', () => {
                accounts[i].streams = Math.max(included, accounts[i].streams - 1);
                renderAccounts();
                render();
            });
            plus.addEventListener('click', () => {
                accounts[i].streams = Math.min(maxStreams, accounts[i].streams + 1);
                renderAccounts();
                render();
            });

            stepper.appendChild(minus);
            stepper.appendChild(count);
            stepper.appendChild(plus);
            visWrap.appendChild(visLabel);
            visWrap.appendChild(stepper);
            visWrap.appendChild(hint);
            visWrap.appendChild(hidden);

            row.appendChild(mailWrap);
            const sep = document.createElement('span');
            sep.className = 'ez-account-sep';
            sep.textContent = ';';
            row.appendChild(sep);
            row.appendChild(visWrap);

            if (i > 0) {
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'btn btn-sm btn-outline-secondary';
                rm.textContent = 'Quitar';
                rm.addEventListener('click', () => {
                    accounts.splice(i, 1);
                    renderAccounts();
                    render();
                });
                row.appendChild(rm);
            }

            box.appendChild(row);
        });
    }

    function render() {
        const chip = selectedChip();
        const pack = packPrice();
        const months = periodMonths();
        const extraUsers = Math.max(0, accounts.length - 1);
        let extraStreams = 0;
        accounts.forEach((a) => { extraStreams += Math.max(0, a.streams - included); });
        const extraUsersPrice = Math.round(extraUsers * extraAccount * 100) / 100;
        const extraStreamsPrice = Math.round(extraStreams * extraStreamMonth * months * 100) / 100;
        const total = Math.round((pack + extraUsersPrice + extraStreamsPrice) * 100) / 100;
        const label = chip?.querySelector('strong')?.textContent || 'Tiempo';

        monthsInput.value = chip?.dataset.months || '1';

        const rows = [
            `${label} · cuenta 1 (${included} visionados): ${money(pack)}`,
        ];
        if (extraUsers > 0) {
            rows.push(`${extraUsers} cuenta(s) extra: ${money(extraUsersPrice)}`);
        }
        if (extraStreams > 0) {
            rows.push(`${extraStreams} visionado(s) extra · ${money(extraStreamMonth)}/mes × ${months}: ${money(extraStreamsPrice)}`);
        }

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

    document.getElementById('ez-add-account')?.addEventListener('click', () => {
        if (accounts.length >= maxAccounts) return;
        const live = Array.from(box.querySelectorAll('input[name="emails[]"]')).map((el) => el.value);
        accounts.forEach((a, i) => { a.email = live[i] ?? a.email; });
        accounts.push({ email: '', streams: included });
        renderAccounts();
        render();
    });

    form.addEventListener('submit', (e) => {
        const filled = Array.from(box.querySelectorAll('input[name="emails[]"]')).filter((el) => el.value.trim());
        if (filled.length < accounts.length) {
            e.preventDefault();
            alert('Escribe el email de cada cuenta individual.');
        }
    });

    renderAccounts();
    render();
})();
