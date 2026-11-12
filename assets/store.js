const STORE_NAMES = ['settings', 'categories', 'category_rules', 'imports', 'payee_labels', 'transactions'];

const DEFAULT_CATEGORIES = [
    ['Scholarship', 'income', '#3f6212'],
    ['Income', 'income', '#4d7c0f'],
    ['Family transfer', 'transfer', '#7c3aed'],
    ['Food', 'expense', '#c2410c'],
    ['Online shopping', 'expense', '#be185d'],
    ['TnG', 'expense', '#0369a1'],
    ['Exam', 'expense', '#1d4ed8'],
    ['Transport', 'expense', '#0f766e'],
    ['Bills', 'expense', '#a16207'],
    ['Cash', 'expense', '#57534e'],
    ['Transfer', 'transfer', '#6d28d9'],
    ['Unknown', 'expense', '#78716c'],
    ['Groceries', 'expense', '#3f6212'],
    ['Housing', 'expense', '#57534e'],
    ['Event', 'expense', '#7c3aed'],
    ['Subscriptions', 'expense', '#be185d'],
    ['CHATGPT', 'expense', '#a16207'],
    ['telecommunication', 'expense', '#0369a1'],
];

const DEFAULT_RULES = [
    [200, 'sarawak energy', 'Scholarship', 'in'],
    [200, 'sarawak energy berhad', 'Scholarship', 'in'],
    [200, 'sr energy', 'Scholarship', 'in'],
    [200, 'energy berhad', 'Scholarship', 'in'],
    [190, 'seb scholarship', 'Scholarship', 'in'],
    [190, 'seb allowance', 'Scholarship', 'in'],
    [185, 'seb', 'Scholarship', 'in'],
    [180, 'sponsor', 'Scholarship', 'in'],
    [175, 'weekly allowance', 'Family transfer', 'any'],
    [170, 'allowance', 'Family transfer', 'in'],
    [165, 'venny soetanto', 'Family transfer', 'any'],
    [165, 'venny', 'Family transfer', 'any'],
    [160, 'mom', 'Family transfer', 'any'],
    [160, 'mum', 'Family transfer', 'any'],
    [160, 'mummy', 'Family transfer', 'any'],
    [160, 'ibu', 'Family transfer', 'any'],
    [160, 'emak', 'Family transfer', 'any'],
    [150, 'touch n go', 'TnG', 'out'],
    [150, "touch 'n go", 'TnG', 'out'],
    [150, 'tng ewallet', 'TnG', 'out'],
    [112, 'grabpay', 'Transport', 'out'],
    [112, 'grab rides', 'Transport', 'out'],
    [110, 'grab', 'Transport', 'out'],
    [110, 'rapid rail', 'Transport', 'out'],
    [125, 'food mart', 'Food', 'out'],
    [125, '7-eleven', 'Food', 'out'],
    [125, 'family mart', 'Food', 'out'],
    [140, 'shopee', 'Online shopping', 'out'],
    [105, 'chatgpt', 'Bills', 'out'],
    [105, 'hostinger', 'Bills', 'out'],
    [90, 'atm', 'Cash', 'out'],
    [90, 'cash withdrawal', 'Cash', 'out'],
];

let dbp = null;

function openStore() {
    if (dbp) return dbp;
    dbp = new Promise((resolve, reject) => {
        const req = indexedDB.open('crying-wallet', 1);
        req.onerror = () => reject(req.error);
        req.onupgradeneeded = () => {
            const db = req.result;
            db.createObjectStore('settings', { keyPath: 'key' });
            db.createObjectStore('categories', { keyPath: 'name' });
            const rules = db.createObjectStore('category_rules', { keyPath: 'id', autoIncrement: true });
            rules.createIndex('pattern', 'pattern', { unique: false });
            const imports = db.createObjectStore('imports', { keyPath: 'id', autoIncrement: true });
            imports.createIndex('filename', 'filename', { unique: false });
            imports.createIndex('statement_month', 'statement_month', { unique: false });
            db.createObjectStore('payee_labels', { keyPath: 'payee' });
            const tx = db.createObjectStore('transactions', { keyPath: 'id', autoIncrement: true });
            tx.createIndex('txn_date', 'txn_date', { unique: false });
            tx.createIndex('fingerprint', 'fingerprint', { unique: true });
            tx.createIndex('receiver', 'receiver', { unique: false });
            tx.createIndex('import_id', 'import_id', { unique: false });
        };
        req.onsuccess = async () => {
            const db = req.result;
            await bootstrap(db);
            resolve(db);
        };
    });
    return dbp;
}

function txDone(tx) {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

function reqVal(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function bootstrap(db) {
    const tx = db.transaction(STORE_NAMES, 'readwrite');
    const settings = tx.objectStore('settings');
    const boot = await reqVal(settings.get('bootstrapped'));
    if (boot && boot.value === '1') {
        await txDone(tx);
        return;
    }
    DEFAULT_CATEGORIES.forEach(([name, kind, color]) => {
        tx.objectStore('categories').put({ name, kind, color });
    });
    DEFAULT_RULES.forEach(([priority, pattern, category, direction]) => {
        tx.objectStore('category_rules').add({ priority, pattern, category, direction });
    });
    const nowYear = new Date().getFullYear();
    settings.put({ key: 'sponsor_name', value: 'Sarawak Energy Berhad' });
    settings.put({ key: 'family_names', value: 'mom, mum, mummy, ibu, emak' });
    settings.put({ key: 'weekly_pocket', value: '250' });
    settings.put({ key: 'term_start', value: nowYear + '-01-01' });
    settings.put({ key: 'term_end', value: nowYear + '-12-31' });
    settings.put({ key: 'expected_term_amount', value: '0' });
    settings.put({ key: 'gemini_model', value: 'gemini-3.5-flash' });
    settings.put({ key: 'bootstrapped', value: '1' });
    await txDone(tx);
}

async function setting(key, fallback = '') {
    const db = await openStore();
    const row = await reqVal(db.transaction('settings').objectStore('settings').get(key));
    return row ? String(row.value) : fallback;
}

async function setSetting(key, value) {
    const db = await openStore();
    const tx = db.transaction('settings', 'readwrite');
    tx.objectStore('settings').put({ key, value: String(value) });
    await txDone(tx);
}

async function getAll(store, indexName, query) {
    const db = await openStore();
    const os = db.transaction(store).objectStore(store);
    const src = indexName ? os.index(indexName) : os;
    return reqVal(query !== undefined ? src.getAll(query) : src.getAll());
}

async function putRow(store, row) {
    const db = await openStore();
    const tx = db.transaction(store, 'readwrite');
    const id = await reqVal(tx.objectStore(store).put(row));
    await txDone(tx);
    return id;
}

async function addRow(store, row) {
    const db = await openStore();
    const tx = db.transaction(store, 'readwrite');
    const id = await reqVal(tx.objectStore(store).add(row));
    await txDone(tx);
    return id;
}

async function deleteRow(store, key) {
    const db = await openStore();
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).delete(key);
    await txDone(tx);
}

async function monthTxns(month) {
    const all = await getAll('transactions');
    return all.filter((t) => String(t.txn_date).slice(0, 7) === month).sort((a, b) => {
        if (a.txn_date === b.txn_date) return a.id - b.id;
        return a.txn_date < b.txn_date ? -1 : 1;
    });
}

async function allMonths() {
    const all = await getAll('transactions');
    const set = new Set(all.map((t) => String(t.txn_date).slice(0, 7)).filter((m) => /^\d{4}-\d{2}$/.test(m)));
    return [...set].sort().reverse();
}

async function exportWallet() {
    const db = await openStore();
    const out = { version: 1, exported_at: new Date().toISOString() };
    for (const name of STORE_NAMES) {
        out[name] = await reqVal(db.transaction(name).objectStore(name).getAll());
    }
    out.settings = (out.settings || []).filter((s) => s.key !== 'gemini_api_key');
    return out;
}

async function importWallet(payload) {
    if (!payload || typeof payload !== 'object') {
        throw new Error('That backup file is not a crying wallet export.');
    }
    const db = await openStore();
    const tx = db.transaction(STORE_NAMES, 'readwrite');
    for (const name of STORE_NAMES) {
        tx.objectStore(name).clear();
        const rows = Array.isArray(payload[name]) ? payload[name] : [];
        for (const row of rows) {
            if (name === 'settings' && row.key === 'gemini_api_key') continue;
            tx.objectStore(name).put(row);
        }
    }
    await txDone(tx);
    const boot = await setting('bootstrapped');
    if (boot !== '1') {
        await bootstrap(db);
    }
}
