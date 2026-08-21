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
    const serverTypeInput = document.getElementById('ez-server-type');
    const tbody = document.getElementById('ez-accounts');
    const tfoot = document.getElementById('ez-ticket-foot');
    const ticketList = document.getElementById('ez-ticket-list');
    const totalEl = document.getElementById('ez-total');
    const liveEl = document.getElementById('ez-live');
    const contractEl = document.getElementById('ez-contract');

    let accounts = [{ email: buyerEmail, streams: included }];

    function money(n) {
        return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function selectedChip() {
        return form.querySelector('.ez-chip[data-months].is-on') || form.querySelector('.ez-chip[data-months]');
    }

    function packPrice() {
        return Number(selectedChip()?.dataset.price || 0);
    }

    function periodMonths() {
        return Math.max(1, Number(selectedChip()?.dataset.months || 1));
    }

    function extrasOf(streams) {
        return Math.max(0, streams - included);
    }

    function totals() {
        const pack = packPrice();
        const months = periodMonths();
        const extraUsers = Math.max(0, accounts.length - 1);
        let extraStreams = 0;
        accounts.forEach((a) => { extraStreams += extrasOf(a.streams); });
        const extraUsersPrice = Math.round(extraUsers * extraAccount * 100) / 100;
        const extraStreamsPrice = Math.round(extraStreams * extraStreamMonth * months * 100) / 100;
        const total = Math.round((pack + extraUsersPrice + extraStreamsPrice) * 100) / 100;
        return { pack, months, extraUsers, extraStreams, extraUsersPrice, extraStreamsPrice, total };
    }

    function rowAmount(i, t) {
        if (i === 0) {
            const extra = extrasOf(accounts[i].streams);
            return t.pack + extra * extraStreamMonth * t.months;
        }
        const extra = extrasOf(accounts[i].streams);
        return extraAccount + extra * extraStreamMonth * t.months;
    }

    function tvs(n) {
        return Array.from({ length: n }, () => '<span class="ez-tv-dot" title="Reproducción">📺</span>').join('');
    }

    function renderLive() {
        if (!liveEl) return;
        liveEl.innerHTML = accounts.map((a, i) => {
            const extra = extrasOf(a.streams);
            const label = i === 0 ? 'Tu casa' : 'Casa ' + (i + 1);
            return `<div class="ez-live-house">
                <span class="ez-live-roof" aria-hidden="true">🏠</span>
                <span class="ez-live-tvs">${tvs(a.streams)}</span>
                <span class="ez-live-cap">${label}: <strong>${a.streams}</strong> a la vez en casa${extra ? ' · ' + extra + ' extra' : ''}</span>
            </div>`;
        }).join('');
    }

    function renderAccounts() {
        const emails = Array.from(tbody.querySelectorAll('input[name="emails[]"]')).map((el) => el.value);
        tbody.innerHTML = '';
        const t = totals();

        accounts.forEach((acc, i) => {
            if (emails[i] !== undefined) acc.email = emails[i];
            const extra = extrasOf(acc.streams);
            const tr = document.createElement('tr');

            const nameTd = document.createElement('td');
            nameTd.dataset.label = 'Cuenta';
            nameTd.innerHTML = i === 0
                ? '<strong>1 · la tuya</strong><span class="ez-td-sub">Pack de meses</span>'
                : `<strong>${i + 1} · extra</strong><span class="ez-td-sub">Otro historial</span>`;

            const mailTd = document.createElement('td');
            mailTd.dataset.label = 'Email';
            const mail = document.createElement('input');
            mail.type = 'email';
            mail.name = 'emails[]';
            mail.className = 'form-control form-control-sm';
            mail.required = true;
            mail.placeholder = 'cuenta@email.com';
            mail.autocomplete = i === 0 ? 'email' : 'off';
            mail.value = acc.email || (i === 0 ? buyerEmail : '');
            mail.addEventListener('input', () => { accounts[i].email = mail.value; });
            mailTd.appendChild(mail);

            const visTd = document.createElement('td');
            visTd.dataset.label = 'Reproducciones';
            visTd.className = 'ez-td-vis';
            const stepper = document.createElement('div');
            stepper.className = 'ez-mini-step';
            const minus = document.createElement('button');
            minus.type = 'button';
            minus.className = 'ez-pm ez-pm-sm';
            minus.setAttribute('aria-label', 'Menos reproducciones');
            minus.textContent = '−';
            const count = document.createElement('strong');
            count.textContent = String(acc.streams);
            const plus = document.createElement('button');
            plus.type = 'button';
            plus.className = 'ez-pm ez-pm-sm';
            plus.setAttribute('aria-label', 'Más reproducciones');
            plus.textContent = '+';
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'streams[]';
            hidden.value = String(acc.streams);
            minus.addEventListener('click', () => {
                accounts[i].streams = Math.max(included, accounts[i].streams - 1);
                paint();
            });
            plus.addEventListener('click', () => {
                accounts[i].streams = Math.min(maxStreams, accounts[i].streams + 1);
                paint();
            });
            stepper.appendChild(minus);
            stepper.appendChild(count);
            stepper.appendChild(plus);
            visTd.appendChild(stepper);
            visTd.appendChild(hidden);

            const extraTd = document.createElement('td');
            extraTd.dataset.label = 'Extra';
            extraTd.innerHTML = extra === 0
                ? `<span class="ez-td-sub">${included} incluidas</span>`
                : `<strong>+${extra}</strong><span class="ez-td-sub">${money(extraStreamMonth)}/mes × ${t.months}</span>`;

            const priceTd = document.createElement('td');
            priceTd.dataset.label = 'Importe';
            priceTd.innerHTML = `<strong>${money(rowAmount(i, t))}</strong>`;

            const actTd = document.createElement('td');
            actTd.className = 'ez-td-act';
            actTd.dataset.label = '';
            if (i > 0) {
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'btn btn-sm btn-outline-secondary';
                rm.textContent = 'Quitar';
                rm.addEventListener('click', () => {
                    accounts.splice(i, 1);
                    paint();
                });
                actTd.appendChild(rm);
            }

            tr.appendChild(nameTd);
            tr.appendChild(mailTd);
            tr.appendChild(visTd);
            tr.appendChild(extraTd);
            tr.appendChild(priceTd);
            tr.appendChild(actTd);
            tbody.appendChild(tr);
        });
    }

    function renderTotals() {
        const t = totals();
        const chip = selectedChip();
        const label = chip?.querySelector('strong')?.textContent || 'Tiempo';
        monthsInput.value = chip?.dataset.months || '1';

        if (tfoot) {
            tfoot.innerHTML = `
                <tr>
                    <td colspan="4">${label} (cuenta 1, ${included} reproducciones)</td>
                    <td>${money(t.pack)}</td>
                    <td></td>
                </tr>
                ${t.extraUsers > 0 ? `<tr>
                    <td colspan="4">${t.extraUsers} cuenta(s) extra</td>
                    <td>${money(t.extraUsersPrice)}</td>
                    <td></td>
                </tr>` : ''}
                ${t.extraStreams > 0 ? `<tr>
                    <td colspan="4">${t.extraStreams} reproducción(es) extra · ${money(extraStreamMonth)}/mes × ${t.months}</td>
                    <td>${money(t.extraStreamsPrice)}</td>
                    <td></td>
                </tr>` : ''}
                <tr class="ez-foot-total">
                    <td colspan="4">Total</td>
                    <td>${money(t.total)}</td>
                    <td></td>
                </tr>`;
        }

        const houses = accounts.length;
        const streams = accounts.reduce((sum, a) => sum + a.streams, 0);
        if (contractEl) {
            contractEl.textContent = `Contratas ${houses} cuenta${houses === 1 ? '' : 's'} individual${houses === 1 ? '' : 'es'} · ${streams} reproducción${streams === 1 ? '' : 'es'} a la vez · cada una solo en su casa.`;
        }

        const rows = [`${label} · cuenta 1 (${included} reproducciones): ${money(t.pack)}`];
        if (t.extraUsers > 0) rows.push(`${t.extraUsers} cuenta(s) extra: ${money(t.extraUsersPrice)}`);
        if (t.extraStreams > 0) {
            rows.push(`${t.extraStreams} reproducción(es) extra · ${money(extraStreamMonth)}/mes × ${t.months}: ${money(t.extraStreamsPrice)}`);
        }
        ticketList.innerHTML = rows.map((r) => `<li>${r}</li>`).join('');
        totalEl.textContent = money(t.total);
        renderLive();
    }

    function paint() {
        renderAccounts();
        renderTotals();
    }

    form.querySelectorAll('.ez-chip[data-months]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.querySelectorAll('.ez-chip[data-months]').forEach((b) => b.classList.remove('is-on'));
            btn.classList.add('is-on');
            paint();
        });
    });

    form.querySelectorAll('.ez-chip[data-server-type]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.querySelectorAll('.ez-chip[data-server-type]').forEach((b) => b.classList.remove('is-on'));
            btn.classList.add('is-on');
            if (serverTypeInput) serverTypeInput.value = btn.dataset.serverType || 'plex';
        });
    });

    document.getElementById('ez-add-account')?.addEventListener('click', () => {
        if (accounts.length >= maxAccounts) return;
        const live = Array.from(tbody.querySelectorAll('input[name="emails[]"]')).map((el) => el.value);
        accounts.forEach((a, i) => { a.email = live[i] ?? a.email; });
        accounts.push({ email: '', streams: included });
        paint();
    });

    form.addEventListener('submit', (e) => {
        const filled = Array.from(tbody.querySelectorAll('input[name="emails[]"]')).filter((el) => el.value.trim());
        if (filled.length < accounts.length) {
            e.preventDefault();
            alert('Escribe el email de cada cuenta individual.');
        }
    });

    paint();
})();
