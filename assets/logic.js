function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function money(amount) {
    const n = Number(amount) || 0;
    const sign = n < 0 ? '-' : '';
    return sign + 'RM ' + Math.abs(n).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function moneyCompact(amount) {
    const abs = Math.abs(Number(amount) || 0);
    if (abs >= 10000) return Math.round(abs / 1000) + 'k';
    if (abs >= 1000) {
        const t = (abs / 1000).toFixed(1).replace(/\.0$/, '');
        return t + 'k';
    }
    if (Math.abs(abs - Math.round(abs)) < 0.009) return String(Math.round(abs));
    return Math.round(abs).toString();
}

function monthLabel(month) {
    const dt = new Date(month + '-01T00:00:00');
    return dt.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
}

function categoryDisplayName(category, kind) {
    if (kind === 'in') {
        return ({
            'Family transfer': 'From mum / family',
            'Scholarship': 'Scholarship (SEB)',
            'Transfer': 'From other people',
            'Transport': 'Transport refunds',
        })[category] || category;
    }
    if (kind === 'out' && category === 'Family transfer') return 'Sent to family';
    return category;
}

function patternMatches(haystack, pattern) {
    const needle = String(pattern || '').trim().toLowerCase();
    if (!needle) return false;
    const quoted = needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp('(^|[^a-z0-9])' + quoted + '([^a-z0-9]|$)', 'u').test(haystack);
}

function isPaymentRail(pattern) {
    return /^(duitnow|ibg|fpx|tng|tngo|mydebit|drc debit|drc credit)$/i.test(String(pattern).trim());
}

function looksLikeScholarship(haystack) {
    return /sarawak\s*energy|\bsr\s*energy|energy\s+berhad|\bseb\b|s\.e\.b/i.test(haystack);
}

async function categorizeTransaction(description, receiver, direction) {
    const name = String(receiver || '').trim();
    if (name && name.toLowerCase() !== 'unknown') {
        const saved = await reqVal((await openStore()).transaction('payee_labels').objectStore('payee_labels').get(name.toLowerCase()));
        if (saved && saved.category) return [saved.category, 'user'];
    }
    const haystack = (name || description || '').toLowerCase();
    if (direction === 'in' && looksLikeScholarship(haystack)) return ['Scholarship', 'auto'];
    const rules = (await getAll('category_rules')).sort((a, b) => (b.priority || 0) - (a.priority || 0) || (a.id || 0) - (b.id || 0));
    for (const rule of rules) {
        if (isPaymentRail(rule.pattern)) continue;
        if (rule.direction !== 'any' && rule.direction !== direction) continue;
        if (rule.pattern && patternMatches(haystack, rule.pattern)) return [rule.category, 'auto'];
    }
    return ['Unknown', 'auto'];
}

async function hydrateRows(rows) {
    const out = [];
    for (const row of rows) {
        const date = parseDate(row.date || row.txn_date || '');
        let description = String(row.description || '').trim();
        const dir = String(row.direction || 'out').toLowerCase();
        const direction = ['in', 'credit', 'deposit'].includes(dir) ? 'in' : 'out';
        const amount = Math.abs(parseFloat(String(row.amount || 0).replace(/,/g, '')) || 0);
        if (!date || !description || amount < 0.01) continue;
        let receiver = String(row.receiver || '').trim() || description;
        description = receiver && receiver.toLowerCase() !== 'unknown' ? receiver : description;
        const [category, category_source] = await categorizeTransaction(description, receiver, direction);
        out.push({
            txn_date: date,
            description,
            receiver,
            amount,
            direction,
            category,
            category_source,
            fingerprint: date + '|' + receiver.toLowerCase() + '|' + amount.toFixed(2) + '|' + direction + '|' + out.length,
            notes: '',
            created_at: new Date().toISOString(),
        });
    }
    return out;
}

function statementMonth(parsed, txns) {
    if (parsed.period_start) return String(parsed.period_start).slice(0, 7);
    const counts = {};
    for (const t of txns) {
        const m = String(t.txn_date).slice(0, 7);
        counts[m] = (counts[m] || 0) + 1;
    }
    return Object.keys(counts).sort((a, b) => counts[b] - counts[a])[0] || new Date().toISOString().slice(0, 7);
}

async function saveImport(filename, txns, parsed) {
    const month = statementMonth(parsed, txns);
    const db = await openStore();
    const existing = await getAll('transactions');
    const tx = db.transaction(['transactions', 'imports', 'settings'], 'readwrite');
    for (const row of existing) {
        if (String(row.txn_date).slice(0, 7) === month) tx.objectStore('transactions').delete(row.id);
    }
    const importId = await reqVal(tx.objectStore('imports').add({
        filename,
        imported_at: new Date().toISOString(),
        row_count: txns.length,
        added_count: txns.length,
        statement_month: month,
        opening_balance: parsed.opening_balance,
        closing_balance: parsed.closing_balance,
        total_withdrawal: parsed.total_withdrawal,
        total_deposit: parsed.total_deposit,
    }));
    for (const row of txns) {
        tx.objectStore('transactions').add({ ...row, import_id: importId });
    }
    tx.objectStore('settings').put({ key: 'last_import_month', value: month });
    await txDone(tx);
    return { added: txns.length, month };
}

async function rememberPayeeLabel(payee, category) {
    const key = String(payee).trim().toLowerCase();
    if (!key || key === 'unknown') return;
    await putRow('payee_labels', { payee: key, category, updated_at: new Date().toISOString() });
    const rules = await getAll('category_rules');
    const hit = rules.find((r) => r.pattern === key);
    if (hit) {
        await putRow('category_rules', { ...hit, category, direction: 'any' });
    } else {
        await addRow('category_rules', { pattern: key, category, direction: 'any', priority: 250 });
    }
}

async function recategorizePayee(id, category) {
    const db = await openStore();
    const row = await reqVal(db.transaction('transactions').objectStore('transactions').get(Number(id)));
    if (!row) throw new Error('Unknown row');
    const payee = String(row.receiver || '').trim();
    const applyName = payee !== '' && payee.toLowerCase() !== 'unknown';
    let updated = 1;
    if (applyName) {
        const all = await getAll('transactions');
        const tx = db.transaction('transactions', 'readwrite');
        updated = 0;
        for (const t of all) {
            if (String(t.receiver || '').trim().toLowerCase() === payee.toLowerCase()) {
                tx.objectStore('transactions').put({ ...t, category, category_source: 'user' });
                updated++;
            }
        }
        await txDone(tx);
        await rememberPayeeLabel(payee, category);
    } else {
        await putRow('transactions', { ...row, category, category_source: 'user' });
    }
    return { updated, payee };
}

async function deleteImport(id) {
    const imports = await getAll('imports');
    const imp = imports.find((i) => i.id === Number(id));
    if (!imp) throw new Error('That upload is already gone.');
    let month = String(imp.statement_month || '');
    const m = String(imp.filename).match(/(20\d{2})(0[1-9]|1[0-2])/);
    if (!month && m) month = m[1] + '-' + m[2];
    const db = await openStore();
    const txns = await getAll('transactions');
    const tx = db.transaction(['transactions', 'imports'], 'readwrite');
    for (const t of txns) {
        if ((month && String(t.txn_date).slice(0, 7) === month) || t.import_id === imp.id) {
            tx.objectStore('transactions').delete(t.id);
        }
    }
    for (const other of imports) {
        if (other.filename === imp.filename || other.id === imp.id) tx.objectStore('imports').delete(other.id);
    }
    await txDone(tx);
}

function spendingGroups(rows, direction) {
    const groups = {};
    for (const row of rows.filter((r) => r.direction === direction)) {
        const cat = row.category || 'Unknown';
        if (!groups[cat]) groups[cat] = { category: cat, total: 0, count: 0, payees: {}, rows: [] };
        groups[cat].total += Number(row.amount);
        groups[cat].count++;
        groups[cat].rows.push(row);
        const payee = String(row.receiver || row.description || 'Unknown').trim();
        if (!groups[cat].payees[payee]) {
            groups[cat].payees[payee] = { name: payee, total: 0, count: 0, id: row.id, direction: row.direction };
        }
        groups[cat].payees[payee].total += Number(row.amount);
        groups[cat].payees[payee].count++;
    }
    const order = direction === 'in'
        ? ['Scholarship', 'Family transfer', 'Transfer', 'Unknown', 'Transport', 'Cash', 'Bills']
        : ['Food', 'Transport', 'Family transfer', 'Exam', 'Online shopping', 'Bills', 'Transfer', 'Cash', 'Scholarship', 'Unknown'];
    const list = Object.values(groups).map((g) => {
        g.payees = Object.values(g.payees).sort((a, b) => b.total - a.total);
        return g;
    });
    list.sort((a, b) => {
        const ia = order.indexOf(a.category); const ib = order.indexOf(b.category);
        const sa = ia < 0 ? 50 : ia; const sb = ib < 0 ? 50 : ib;
        if (sa === sb) return b.total - a.total;
        return sa - sb;
    });
    return list;
}

function monthBalances(imports, month) {
    const compact = month.replace('-', '');
    const row = imports.find((i) => i.statement_month === month)
        || imports.find((i) => String(i.filename).includes(compact));
    if (!row) return { opening: null, closing: null, withdrawal: null, deposit: null };
    const num = (v) => (v === null || v === undefined || v === '') ? null : Number(v);
    return { opening: num(row.opening_balance), closing: num(row.closing_balance), withdrawal: num(row.total_withdrawal), deposit: num(row.total_deposit) };
}

async function geminiKey() {
    return setting('gemini_api_key', '');
}

async function classifyWithGemini(txns) {
    const key = await geminiKey();
    if (!key) return txns;
    const unknown = txns.filter((t) => t.category === 'Unknown' && t.receiver && t.receiver.toLowerCase() !== 'unknown');
    if (!unknown.length) return txns;
    try {
        const names = [...new Set(unknown.map((t) => t.receiver))].slice(0, 40);
        const res = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' + encodeURIComponent(key), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                contents: [{ parts: [{ text: 'Classify each Malaysian bank payee as one of: Food, Transport, TnG, Bills, Exam, Family transfer, Scholarship, Transfer, Cash, Online shopping, Groceries, Subscriptions, Event, Unknown. Return JSON {\"labels\":{\"NAME\":\"Category\"}}.\n' + names.join('\n') }] }],
                generationConfig: { temperature: 0, responseMimeType: 'application/json' },
            }),
        });
        const body = await res.json();
        const text = body?.candidates?.[0]?.content?.parts?.[0]?.text || '{}';
        const parsed = JSON.parse(text);
        const labels = parsed.labels || parsed;
        for (const t of txns) {
            const hit = labels[t.receiver];
            if (hit) {
                t.category = hit;
                t.category_source = 'ai';
            }
        }
    } catch (e) {
        // local categories still apply
    }
    return txns;
}
