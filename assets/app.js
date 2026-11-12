if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

const isSpa = !!document.getElementById('screen');

const state = { month: '', day: '', flash: '' };

function route() {
    const hash = (location.hash || '#/').replace(/^#/, '');
    const parts = hash.split('/').filter(Boolean);
    const page = parts[0] || '';
    return {
        page: page === 'time' ? 'timeline' : page === 'add' ? 'upload' : page === 'pocket' ? 'scholarship' : page || 'dashboard',
        month: parts[1] && /^\d{4}-\d{2}$/.test(parts[1]) ? parts[1] : '',
        day: parts[2] && /^\d{4}-\d{2}-\d{2}$/.test(parts[2]) ? parts[2] : '',
    };
}

function go(path) {
    location.hash = path;
}

function monthSelect(month, extra = '') {
    return `<select id="month" aria-label="Month">${extra}</select>`.replace('</select>', '');
}

async function monthOptionsHtml(selected) {
    let months = await allMonths();
    if (!months.length) months = [new Date().toISOString().slice(0, 7)];
    if (selected && !months.includes(selected)) months.unshift(selected);
    return months.map((m) => `<option value="${m}" ${m === selected ? 'selected' : ''}>${esc(monthLabel(m))}</option>`).join('');
}

function catSelect(id, payee, dir, cat, cats) {
    const opts = cats.map((name) => `<option value="${esc(name)}" ${cat === name ? 'selected' : ''}>${esc(categoryDisplayName(name, dir))}</option>`).join('');
    return `<select id="cat-${id}" class="cat-select" data-recategorize data-apply-payee data-id="${id}" data-payee="${esc(payee)}" data-direction="${esc(dir)}">${opts}</select>`;
}

function categoryStackHtml(groups, total, kind, cats) {
    if (!groups.length) return '<p class="hint">None this month.</p>';
    return '<div class="cat-grid">' + groups.map((group) => {
        const pct = total > 0 ? Math.round((group.total / total) * 100) : 0;
        const names = group.payees.slice(0, 3).map((p) => p.name).join(' · ');
        const share = kind === 'in' ? '% of cash in' : '% of cash out';
        const unit = kind === 'in' ? (group.count === 1 ? 'credit' : 'credits') : (group.count === 1 ? 'payment' : 'payments');
        const label = categoryDisplayName(group.category, kind);
        const payees = group.payees.map((payee) => `
            <li>
                <details class="payee-row">
                    <summary>
                        <span class="tl-who">${esc(payee.name)}</span>
                        <strong>${money(payee.total)}</strong>
                        <em>${payee.count === 1 ? '1 time' : payee.count + ' times'}</em>
                    </summary>
                    <div class="tl-edit">
                        <label for="dash-cat-${payee.id}">Type for this name</label>
                        ${catSelect(payee.id, payee.name, payee.direction || kind, group.category, cats)}
                        <p class="hint">Saves this name for every day, every month, and the next statement.</p>
                        <span class="saved"></span>
                    </div>
                </details>
            </li>`).join('');
        return `<details class="cat-card" data-category="${esc(group.category)}">
            <summary>
                <span class="cat-name">${esc(label)}</span>
                <span class="cat-amt">${money(group.total)}</span>
                <span class="cat-sub">${group.count} ${unit} · ${pct}${share}</span>
                <span class="cat-payees">${esc(names)}</span>
            </summary>
            <ul class="payee-roll">${payees}</ul>
        </details>`;
    }).join('') + '</div>';
}

async function renderDashboard(month) {
    const rows = await monthTxns(month);
    const imports = await getAll('imports');
    const cats = (await getAll('categories')).map((c) => c.name).sort();
    const stats = rows.reduce((a, t) => {
        if (t.direction === 'in') a.cash_in += Number(t.amount);
        else a.cash_out += Number(t.amount);
        return a;
    }, { cash_in: 0, cash_out: 0 });
    const bal = monthBalances(imports, month);
    const cashOut = bal.withdrawal != null ? bal.withdrawal : stats.cash_out;
    const cashIn = bal.deposit != null ? bal.deposit : stats.cash_in;
    const computed = bal.opening != null ? Math.round((bal.opening + cashIn - cashOut) * 100) / 100 : null;
    const tallyOk = bal.opening != null && bal.closing != null && computed != null && Math.abs(computed - bal.closing) < 0.06;
    const inGroups = spendingGroups(rows, 'in');
    const outGroups = spendingGroups(rows, 'out');
    const hasSeb = inGroups.some((g) => g.category === 'Scholarship');
    document.getElementById('page-title').textContent = new Date(month + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    return `
        <div class="hero">
            <div>
                <p class="eyebrow-inline">Month summary</p>
                <p class="lede" style="margin:0">This list lives on this phone. Open a type, tap a name, set its type.</p>
            </div>
            <form data-month-nav="dashboard"><select id="month" aria-label="Month">${await monthOptionsHtml(month)}</select></form>
        </div>
        <section class="glance glance-balance">
            ${bal.opening != null ? `<article><div class="label">Start of month · opening</div><div class="value">${money(bal.opening)}</div></article>` : ''}
            <article><div class="label">Withdrawal · cash out</div><div class="value out">${money(cashOut)}</div></article>
            <article><div class="label">Deposit · cash in</div><div class="value in">${money(cashIn)}</div></article>
            ${bal.closing != null ? `<article class="remain"><div class="label">End of month · outstanding</div><div class="value">${money(bal.closing)}</div></article>` : ''}
        </section>
        ${bal.opening != null && bal.closing != null && computed != null ? `<p class="hint tally ${tallyOk ? 'ok' : ''}">${money(bal.opening)} + deposit − withdrawal = ${money(computed)}${tallyOk ? ' · matches end of month' : ' · statement end is ' + money(bal.closing)}</p>` : ''}
        <h2 class="section-title">Money in</h2>
        ${hasSeb ? '' : '<p class="hint">This statement has no Sarawak Energy / scholarship credit. Mum’s weekly money is “From mum / family”.</p>'}
        ${categoryStackHtml(inGroups, cashIn, 'in', cats)}
        <h2 class="section-title">Money out</h2>
        ${categoryStackHtml(outGroups, cashOut, 'out', cats)}
    `;
}

async function renderTimeline(month, picked) {
    const rows = await monthTxns(month);
    const cats = (await getAll('categories')).map((c) => c.name).sort();
    const byDay = {};
    for (const row of rows) {
        const date = row.txn_date;
        if (!byDay[date]) {
            const dt = new Date(date + 'T00:00:00');
            byDay[date] = { label: dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }), cash_in: 0, cash_out: 0, rows: [] };
        }
        if (row.direction === 'in') byDay[date].cash_in += Number(row.amount);
        else byDay[date].cash_out += Number(row.amount);
        byDay[date].rows.push(row);
    }
    const keys = Object.keys(byDay);
    if (!picked || picked.slice(0, 7) !== month) picked = keys.length ? keys.sort().pop() : month + '-01';
    const sheet = byDay[picked] || { label: new Date(picked + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }), cash_in: 0, cash_out: 0, rows: [] };
    const start = new Date(month + '-01T00:00:00');
    const daysInMonth = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    const pad = start.getDay();
    const weeks = Math.ceil((pad + daysInMonth) / 7);
    let cells = '';
    for (let i = 0; i < weeks * 7; i++) {
        const dayNum = i - pad + 1;
        if (dayNum < 1 || dayNum > daysInMonth) { cells += '<div class="cal-cell pad"></div>'; continue; }
        const iso = month + '-' + String(dayNum).padStart(2, '0');
        const info = byDay[iso];
        const cashIn = info ? info.cash_in : 0;
        const cashOut = info ? info.cash_out : 0;
        const busy = cashIn >= 0.01 || cashOut >= 0.01;
        const cls = 'cal-cell' + (busy ? ' on' : ' idle') + (iso === picked ? ' sel' : '');
        cells += `<a class="${cls}" href="#/time/${month}/${iso}"><span class="cal-n">${dayNum}</span>${cashOut >= 0.01 ? `<span class="cal-out">−${esc(moneyCompact(cashOut))}</span>` : ''}${cashIn >= 0.01 ? `<span class="cal-in">+${esc(moneyCompact(cashIn))}</span>` : ''}</a>`;
    }
    const list = sheet.rows.length ? sheet.rows.map((row) => {
        const who = row.receiver || 'Unknown';
        return `<details class="tl-row">
            <summary>
                <div class="tl-who">${esc(who)}</div>
                <div class="tl-amt ${esc(row.direction)}">${row.direction === 'out' ? '−' : '+'}${money(row.amount)}</div>
                <div class="tl-cat">${esc(categoryDisplayName(row.category, row.direction))}</div>
            </summary>
            <div class="tl-edit">
                <label for="cat-${row.id}">Type for this name</label>
                ${catSelect(row.id, who, row.direction, row.category, cats)}
                <p class="hint">Saves this name for every day, every month, and the next statement.</p>
                <span class="saved"></span>
            </div>
        </details>`;
    }).join('') : '<p class="hint">Nothing on this date.</p>';
    document.getElementById('page-title').textContent = 'Timeline';
    return `
        <div class="hero">
            <p class="lede" style="margin:0">Tap a date, then tap a name to change its type.</p>
            <form data-month-nav="timeline"><select id="month" aria-label="Month">${await monthOptionsHtml(month)}</select></form>
        </div>
        <div class="cal">
            <div class="cal-weekdays" aria-hidden="true"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>
            <div class="cal-grid">${cells}</div>
        </div>
        <section class="day-sheet" id="day">
            <h2>${esc(sheet.label)}</h2>
            <p class="day-sheet-sum"><span class="out">Out ${money(sheet.cash_out)}</span><span class="in">In ${money(sheet.cash_in)}</span></p>
            ${list}
        </section>`;
}

async function renderUpload() {
    const hasKey = (await geminiKey()) !== '';
    const imports = (await getAll('imports')).sort((a, b) => String(b.statement_month).localeCompare(String(a.statement_month)) || b.id - a.id);
    const seen = new Set();
    const unique = imports.filter((i) => { if (seen.has(i.filename)) return false; seen.add(i.filename); return true; });
    document.getElementById('page-title').textContent = 'Add statement';
    const list = unique.length ? unique.map((imp) => {
        let month = String(imp.statement_month || '');
        const mm = String(imp.filename).match(/(20\d{2})(0[1-9]|1[0-2])/);
        if (!month && mm) month = mm[1] + '-' + mm[2];
        const when = imp.imported_at ? new Date(imp.imported_at).toLocaleString() : '';
        return `<article class="import-card">
            <div>
                <strong>${esc(month ? monthLabel(month) : 'Month unknown')}</strong>
                <div class="hint">${esc(imp.filename)}</div>
                <div class="hint">${Number(imp.added_count || 0)} rows · ${esc(when)}</div>
            </div>
            <div class="import-actions">
                ${month ? `<a href="#/${month}">Types</a>` : ''}
                <button class="link-btn" data-delete-import="${imp.id}" type="button">Delete</button>
            </div>
        </article>`;
    }).join('') : '<p class="hint">Nothing here yet. Files you read on this phone stay on this phone.</p>';
    return `
        ${state.flash ? `<div class="flash">${esc(state.flash)}</div>` : ''}
        <p class="lede">Read a Bank Islam PDF here. The file is not sent to GitHub. Rows stay in this phone’s copy of the app.</p>
        <form class="drop" id="upload-form">
            <p>Bank PDFs</p>
            <input type="file" name="statement" accept=".pdf,application/pdf" multiple required>
            <p><button class="btn" type="submit">Read statements</button></p>
            <p class="hint">${hasKey ? 'AI key is saved on this phone only.' : 'Text PDFs work on the phone. A Gemini key is only needed for awkward scans.'}</p>
        </form>
        <h2 class="section-title">Uploaded</h2>
        <div class="import-list">${list}</div>
        ${hasKey ? `<div class="card" style="margin-top:12px"><p class="hint" style="margin:0">The AI key stays on this phone until you remove it.</p>
            <p style="margin-top:10px"><button class="btn ghost" type="button" id="remove-key">Remove saved key</button></p></div>`
            : `<form class="card stack" id="key-form" style="margin-top:12px">
                <label>One-time Gemini key<input type="password" name="gemini_api_key" placeholder="Paste once from Google AI Studio" autocomplete="off"></label>
                <p class="hint">Get a free key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>. It is stored on this phone, not on GitHub.</p>
                <button class="btn ghost" type="submit">Save key on this phone</button>
            </form>`}
    `;
}

async function renderPocket() {
    const all = await getAll('transactions');
    const termStart = await setting('term_start', new Date().getFullYear() + '-01-01');
    const termEnd = await setting('term_end', new Date().getFullYear() + '-12-31');
    const expected = Number(await setting('expected_term_amount', '0'));
    const weekly = await setting('weekly_pocket', '250');
    const sponsor = await setting('sponsor_name', 'Sarawak Energy Berhad');
    const family = await setting('family_names', 'mom, mum, mummy, ibu, emak');
    let received = 0, fromFamily = 0, toFamily = 0, spent = 0;
    for (const t of all) {
        if (t.txn_date < termStart || t.txn_date > termEnd) continue;
        const amt = Number(t.amount);
        if (t.direction === 'in' && t.category === 'Scholarship') received += amt;
        if (t.direction === 'in' && t.category === 'Family transfer') fromFamily += amt;
        if (t.direction === 'out' && t.category === 'Family transfer') toFamily += amt;
        if (t.direction === 'out' && t.category !== 'Family transfer') spent += amt;
    }
    const known = expected > 0;
    document.getElementById('page-title').textContent = 'Pocket';
    return `
        <p class="lede">Leftover scholarship only shows after you enter the term allowance. These figures stay on this phone.</p>
        <section class="cards">
            <article class="card in"><div class="label">SEB / scholarship in</div><div class="value">${money(received)}</div></article>
            <article class="card in"><div class="label">From family</div><div class="value">${money(fromFamily)}</div></article>
            <article class="card"><div class="label">With family</div><div class="value">${money(Math.max(0, toFamily - fromFamily))}</div></article>
            <article class="${known ? 'card remain' : 'card'}"><div class="label">Left this term</div><div class="value">${known ? money(expected - spent) : 'Set amount'}</div></article>
        </section>
        <form class="card stack" id="pocket-form">
            <label>Sponsor name <input name="sponsor_name" value="${esc(sponsor)}" required></label>
            <label>Family names <input name="family_names" value="${esc(family)}"></label>
            <label>Term start <input type="date" name="term_start" value="${esc(termStart)}"></label>
            <label>Term end <input type="date" name="term_end" value="${esc(termEnd)}"></label>
            <label>Expected term amount <input type="number" step="0.01" name="expected_term_amount" value="${esc(String(expected))}"></label>
            <label>Weekly pocket <input type="number" step="0.01" name="weekly_pocket" value="${esc(weekly)}"></label>
            <button class="btn" type="submit">Save scholarship settings</button>
        </form>`;
}

async function renderMore() {
    document.getElementById('page-title').textContent = 'More';
    return `
        ${state.flash ? `<div class="flash">${esc(state.flash)}</div>` : ''}
        <p class="lede">GitHub only hosts the app. Your statements, types, and Gemini key stay in this phone’s storage for crying wallet.</p>
        <button class="menu-link" type="button" id="backup-export">Download a backup (JSON)</button>
        <label class="menu-link">Restore a backup
            <input type="file" id="backup-import" accept="application/json,.json" hidden>
        </label>
        <p class="hint">If you clear Safari / Chrome site data, the wallet on this phone is wiped. Keep a JSON backup in Files.</p>
        <p class="hint">On iPhone: Share → Add to Home Screen so it opens like an app and keeps data more reliably.</p>
    `;
}

async function currentMonth() {
    const last = await setting('last_import_month', '');
    const months = await allMonths();
    return last || months[0] || new Date().toISOString().slice(0, 7);
}

async function render() {
    const r = route();
    let month = r.month || state.month || await currentMonth();
    state.month = month;
    document.body.className = r.page;
    document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.tab === r.page));
    const screen = document.getElementById('screen');
    const openCat = sessionStorage.getItem('cw-open-cat');
    if (openCat) sessionStorage.removeItem('cw-open-cat');
    try {
        if (r.page === 'timeline') screen.innerHTML = await renderTimeline(month, r.day);
        else if (r.page === 'upload') screen.innerHTML = await renderUpload();
        else if (r.page === 'scholarship') screen.innerHTML = await renderPocket();
        else if (r.page === 'more') screen.innerHTML = await renderMore();
        else screen.innerHTML = await renderDashboard(month);
    } catch (e) {
        screen.innerHTML = `<p class="flash error">${esc(e.message || e)}</p>`;
    }
    state.flash = '';
    if (openCat) {
        const card = document.querySelector('.cat-card[data-category="' + openCat.replace(/"/g, '') + '"]');
        if (card) { card.open = true; card.scrollIntoView({ block: 'nearest' }); }
    }
}

document.addEventListener('change', async (event) => {
    const monthSel = event.target.closest('[data-month-nav] select');
    if (monthSel) {
        const nav = event.target.closest('[data-month-nav]').getAttribute('data-month-nav');
        state.month = monthSel.value;
        go(nav === 'timeline' ? '#/time/' + monthSel.value : '#/' + monthSel.value);
        return;
    }
    const select = event.target.closest('[data-recategorize]');
    if (!select) return;
    if (!isSpa) {
        const id = select.getAttribute('data-id');
        const category = select.value;
        const applyPayee = select.hasAttribute('data-apply-payee');
        const res = await fetch('index.php?page=api&action=recategorize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, category, apply_payee: applyPayee }),
        });
        const data = await res.json();
        const note = select.parentElement.querySelector('.saved');
        if (note) {
            note.textContent = data.ok ? (data.updated > 1 ? 'saved for ' + data.updated + ' rows' : 'saved') : 'failed';
            note.classList.add('show');
            setTimeout(() => note.classList.remove('show'), 1600);
        }
        if (data.ok && applyPayee) {
            const payee = (select.getAttribute('data-payee') || '').toLowerCase();
            document.querySelectorAll('[data-recategorize][data-apply-payee]').forEach((other) => {
                if ((other.getAttribute('data-payee') || '').toLowerCase() !== payee) return;
                other.value = category;
                const cat = other.closest('.tl-row')?.querySelector('.tl-cat');
                const opt = other.selectedOptions && other.selectedOptions[0];
                if (cat && opt) cat.textContent = opt.textContent;
            });
            const card = select.closest('.cat-card[data-category]');
            if (card) {
                sessionStorage.setItem('cw-open-cat', category);
                window.location.reload();
            }
        }
        return;
    }
    try {
        const data = await recategorizePayee(select.getAttribute('data-id'), select.value);
        const note = select.parentElement.querySelector('.saved');
        if (note) {
            note.textContent = data.updated > 1 ? 'saved for ' + data.updated + ' rows' : 'saved';
            note.classList.add('show');
        }
        if (select.closest('.cat-card')) {
            sessionStorage.setItem('cw-open-cat', select.value);
            render();
        } else {
            const payee = (select.getAttribute('data-payee') || '').toLowerCase();
            document.querySelectorAll('[data-recategorize][data-apply-payee]').forEach((other) => {
                if ((other.getAttribute('data-payee') || '').toLowerCase() !== payee) return;
                other.value = select.value;
                const cat = other.closest('.tl-row')?.querySelector('.tl-cat');
                const opt = other.selectedOptions && other.selectedOptions[0];
                if (cat && opt) cat.textContent = opt.textContent;
            });
        }
    } catch (e) {
        alert(e.message || 'Could not save');
    }
});

document.addEventListener('submit', async (event) => {
    if (event.target.id === 'upload-form') {
        event.preventDefault();
        const files = [...event.target.statement.files];
        const ok = []; const fail = [];
        for (const file of files) {
            try {
                const buf = await file.arrayBuffer();
                const parsed = parsePdfBuffer(buf);
                let txns = await hydrateRows(parsed.rows);
                txns = await classifyWithGemini(txns);
                const result = await saveImport(file.name, txns, parsed);
                ok.push(file.name + ' · ' + monthLabel(result.month) + ' · ' + result.added + ' rows');
                state.month = result.month;
            } catch (e) {
                fail.push(file.name + ' · ' + (e.message || e));
            }
        }
        state.flash = [ok.length ? ok.join('; ') : '', fail.length ? 'Could not read: ' + fail.join('; ') : ''].filter(Boolean).join(' ');
        render();
        return;
    }
    if (event.target.id === 'key-form') {
        event.preventDefault();
        const key = String(new FormData(event.target).get('gemini_api_key') || '').trim();
        if (!key) return;
        await setSetting('gemini_api_key', key);
        state.flash = 'Key saved on this phone.';
        render();
        return;
    }
    if (event.target.id === 'pocket-form') {
        event.preventDefault();
        const fd = new FormData(event.target);
        for (const [k, v] of fd.entries()) await setSetting(k, v);
        state.flash = 'Saved.';
        render();
    }
});

document.addEventListener('click', async (event) => {
    const del = event.target.closest('[data-delete-import]');
    if (del) {
        if (!confirm('Remove this statement and its rows from this phone?')) return;
        await deleteImport(del.getAttribute('data-delete-import'));
        state.flash = 'Statement removed.';
        render();
        return;
    }
    if (event.target.id === 'remove-key') {
        await setSetting('gemini_api_key', '');
        state.flash = 'Key removed.';
        render();
        return;
    }
    if (event.target.id === 'backup-export') {
        const data = await exportWallet();
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'crying-wallet-backup.json';
        a.click();
        return;
    }
    if (event.target.closest('label.menu-link') && event.target.id !== 'backup-import') {
        const input = document.getElementById('backup-import');
        if (input) input.click();
    }
});

document.addEventListener('change', async (event) => {
    if (event.target.id !== 'backup-import') return;
    const file = event.target.files && event.target.files[0];
    if (!file) return;
    try {
        const payload = JSON.parse(await file.text());
        await importWallet(payload);
        state.flash = 'Backup restored on this phone.';
        render();
    } catch (e) {
        alert(e.message || 'Could not restore that file.');
    }
});

if (isSpa) {
    window.addEventListener('hashchange', render);
    openStore().then(render).catch((e) => {
        document.getElementById('screen').innerHTML = `<p class="flash error">${esc(e.message || e)}</p>`;
    });
} else {
    document.addEventListener('DOMContentLoaded', () => {
        const openCat = sessionStorage.getItem('cw-open-cat');
        if (!openCat) return;
        sessionStorage.removeItem('cw-open-cat');
        const card = document.querySelector('.cat-card[data-category="' + openCat.replace(/"/g, '') + '"]');
        if (card) {
            card.open = true;
            card.scrollIntoView({ block: 'nearest' });
        }
    });
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-ajax-rule], [data-ajax-category]');
        if (!form) return;
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());
        const action = form.hasAttribute('data-ajax-category') ? 'add_category' : 'add_rule';
        if (action === 'add_category') payload.name = payload.category_name;
        const res = await fetch('index.php?page=api&action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.ok) window.location.reload();
        else alert(data.error || 'Could not save');
    });
}
