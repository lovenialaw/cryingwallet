<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir . DIRECTORY_SEPARATOR . 'wallet.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    ensure_extra_rules($pdo);
    return $pdo;
}

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void
{
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $col) {
        if (strcasecmp((string) $col['name'], $column) === 0) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $ddl);
}

function migrate(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL DEFAULT "expense",
            color TEXT NOT NULL DEFAULT "#78716c"
        );

        CREATE TABLE IF NOT EXISTS category_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pattern TEXT NOT NULL,
            category TEXT NOT NULL,
            direction TEXT NOT NULL DEFAULT "any",
            priority INTEGER NOT NULL DEFAULT 100
        );

        CREATE TABLE IF NOT EXISTS imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            imported_at TEXT NOT NULL,
            row_count INTEGER NOT NULL DEFAULT 0,
            added_count INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS payee_labels (
            payee TEXT PRIMARY KEY,
            category TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_id INTEGER,
            txn_date TEXT NOT NULL,
            description TEXT NOT NULL,
            receiver TEXT NOT NULL DEFAULT "",
            amount REAL NOT NULL,
            direction TEXT NOT NULL CHECK (direction IN ("in", "out")),
            category TEXT NOT NULL DEFAULT "Unknown",
            category_source TEXT NOT NULL DEFAULT "auto",
            fingerprint TEXT NOT NULL UNIQUE,
            notes TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY (import_id) REFERENCES imports(id)
        );
    ');

    ensure_column($pdo, 'imports', 'statement_month', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'imports', 'opening_balance', 'REAL');
    ensure_column($pdo, 'imports', 'closing_balance', 'REAL');
    ensure_column($pdo, 'imports', 'total_withdrawal', 'REAL');
    ensure_column($pdo, 'imports', 'total_deposit', 'REAL');

    if (setting('bootstrapped') === '1') {
        return;
    }

    $categories = [
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
    ];

    $insertCat = $pdo->prepare('INSERT OR IGNORE INTO categories (name, kind, color) VALUES (?, ?, ?)');
    foreach ($categories as $row) {
        $insertCat->execute($row);
    }

    $rules = [
        [200, 'sarawak energy', 'Scholarship', 'in'],
        [200, 'sarawak energy berhad', 'Scholarship', 'in'],
        [200, 'sr energy', 'Scholarship', 'in'],
        [200, 'energy berhad', 'Scholarship', 'in'],
        [190, 'seb scholarship', 'Scholarship', 'in'],
        [190, 'seb allowance', 'Scholarship', 'in'],
        [185, 'seb', 'Scholarship', 'in'],
        [180, 'sponsor', 'Scholarship', 'in'],
        [170, 'allowance', 'Family transfer', 'in'],
        [160, 'mom', 'Family transfer', 'any'],
        [160, 'mum', 'Family transfer', 'any'],
        [160, 'mummy', 'Family transfer', 'any'],
        [160, 'ibu', 'Family transfer', 'any'],
        [160, 'emak', 'Family transfer', 'any'],
        [150, 'touch n go', 'TnG', 'out'],
        [150, 'touch \'n go', 'TnG', 'out'],
        [150, 'tng ewallet', 'TnG', 'out'],
        [150, 'tngo', 'TnG', 'out'],
        [150, 'tng ', 'TnG', 'out'],
        [140, 'shopee', 'Online shopping', 'out'],
        [140, 'lazada', 'Online shopping', 'out'],
        [140, 'tiktok', 'Online shopping', 'out'],
        [140, 'shein', 'Online shopping', 'out'],
        [140, 'temu', 'Online shopping', 'out'],
        [140, 'zalora', 'Online shopping', 'out'],
        [130, 'grabfood', 'Food', 'out'],
        [130, 'foodpanda', 'Food', 'out'],
        [130, 'grab food', 'Food', 'out'],
        [125, 'mcdonald', 'Food', 'out'],
        [125, 'kfc', 'Food', 'out'],
        [125, 'tealive', 'Food', 'out'],
        [125, 'secret recipe', 'Food', 'out'],
        [125, 'starbucks', 'Food', 'out'],
        [125, 'zus coffee', 'Food', 'out'],
        [125, 'family mart', 'Food', 'out'],
        [125, '7-eleven', 'Food', 'out'],
        [125, '7 eleven', 'Food', 'out'],
        [125, 'kk super mart', 'Food', 'out'],
        [125, 'mynews', 'Food', 'out'],
        [120, 'exam', 'Exam', 'out'],
        [120, 'muet', 'Exam', 'out'],
        [120, 'mpm', 'Exam', 'out'],
        [120, 'university', 'Exam', 'out'],
        [120, 'uitm', 'Exam', 'out'],
        [120, 'unimas', 'Exam', 'out'],
        [120, 'tuition', 'Exam', 'out'],
        [110, 'grab', 'Transport', 'out'],
        [110, 'maxim', 'Transport', 'out'],
        [110, 'rapidkl', 'Transport', 'out'],
        [110, 'ktm', 'Transport', 'out'],
        [110, 'petrol', 'Transport', 'out'],
        [110, 'shell', 'Transport', 'out'],
        [110, 'petronas', 'Transport', 'out'],
        [100, 'tnb', 'Bills', 'out'],
        [100, 'unifi', 'Bills', 'out'],
        [100, 'maxis', 'Bills', 'out'],
        [100, 'celcom', 'Bills', 'out'],
        [100, 'digi', 'Bills', 'out'],
        [100, 'indah water', 'Bills', 'out'],
        [90, 'atm', 'Cash', 'out'],
        [90, 'cash withdrawal', 'Cash', 'out'],
        [90, 'cash wd', 'Cash', 'out'],
        [80, 'salary', 'Income', 'in'],
        [80, 'gaji', 'Income', 'in'],
        [70, 'duitnow', 'Transfer', 'any'],
        [70, 'ibg', 'Transfer', 'any'],
        [70, 'fpx', 'Transfer', 'any'],
    ];

    $insertRule = $pdo->prepare('INSERT INTO category_rules (priority, pattern, category, direction) VALUES (?, ?, ?, ?)');
    foreach ($rules as $row) {
        $insertRule->execute($row);
    }

    set_setting('sponsor_name', 'Sarawak Energy Berhad');
    set_setting('family_names', 'mom, mum, mummy, ibu, emak');
    set_setting('weekly_pocket', '250');
    set_setting('term_start', date('Y-01-01'));
    set_setting('term_end', date('Y-12-31'));
    set_setting('expected_term_amount', '0');
    set_setting('daily_limit', '0');
    set_setting('blocker_enabled', '0');
    set_setting('bootstrapped', '1');
}

function ensure_extra_rules(PDO $pdo): void
{
    $extras = [
        [175, 'weekly allowance', 'Family transfer', 'any'],
        [200, 'sr energy', 'Scholarship', 'in'],
        [200, 'energy berhad', 'Scholarship', 'in'],
        [190, 'seb allowance', 'Scholarship', 'in'],
        [185, 'seb', 'Scholarship', 'in'],
        [165, 'venny soetanto', 'Family transfer', 'any'],
        [165, 'venny', 'Family transfer', 'any'],
        [112, 'grabpay', 'Transport', 'out'],
        [112, 'grab rides', 'Transport', 'out'],
        [105, 'chatgpt', 'Bills', 'out'],
        [105, 'openai', 'Bills', 'out'],
        [105, 'hostinger', 'Bills', 'out'],
        [105, 'cursor, ai', 'Bills', 'out'],
        [125, 'yi poh', 'Food', 'out'],
        [108, 'duitnow qr', 'Transfer', 'out'],
        [125, 'seng kee', 'Food', 'out'],
        [125, 'chicken rice', 'Food', 'out'],
        [125, 'bingsu', 'Food', 'out'],
        [125, 'bite & taste', 'Food', 'out'],
        [110, 'rapid rail', 'Transport', 'out'],
        [140, 'aeon', 'Online shopping', 'out'],
        [140, 'pdfguru', 'Online shopping', 'out'],
        [140, 'pdf_guru', 'Online shopping', 'out'],
        [140, 'thepdfguru', 'Online shopping', 'out'],
        [140, 'pdfgurucom', 'Online shopping', 'out'],
        [125, 'food mart', 'Food', 'out'],
    ];
    $check = $pdo->prepare('SELECT id FROM category_rules WHERE pattern = ? LIMIT 1');
    $ins = $pdo->prepare('INSERT INTO category_rules (priority, pattern, category, direction) VALUES (?, ?, ?, ?)');
    foreach ($extras as $row) {
        $check->execute([$row[1]]);
        if (!$check->fetch()) {
            $ins->execute($row);
        }
    }

    $pdo->exec("UPDATE category_rules SET category = 'Family transfer', direction = 'in' WHERE pattern = 'allowance' AND category = 'Scholarship'");
    $pdo->exec("UPDATE transactions SET category = 'Family transfer', category_source = 'auto'
        WHERE direction = 'in' AND category_source != 'user'
        AND (LOWER(receiver) LIKE '%weekly allowance%' OR LOWER(receiver) LIKE '%venny%'
             OR LOWER(description) LIKE '%weekly allowance%' OR LOWER(description) LIKE '%venny%')");
}

function setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? (string) $row['value'] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function money(float $amount): string
{
    $sign = $amount < 0 ? '-' : '';
    return $sign . 'RM ' . number_format(abs($amount), 2);
}

function export_wallet_payload(): array
{
    $pdo = db();
    $tables = ['categories', 'category_rules', 'imports', 'payee_labels', 'transactions'];
    $out = ['version' => 1, 'exported_at' => date('c')];
    foreach ($tables as $table) {
        $out[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
    }
    $settings = $pdo->query('SELECT key, value FROM settings')->fetchAll();
    $out['settings'] = array_values(array_filter($settings, static fn($row) => ($row['key'] ?? '') !== 'gemini_api_key'));
    return $out;
}

function money_compact(float $amount): string
{
    $abs = abs($amount);
    if ($abs >= 10000) {
        return number_format($abs / 1000, 0) . 'k';
    }
    if ($abs >= 1000) {
        $k = $abs / 1000;
        $text = rtrim(rtrim(number_format($k, 1, '.', ''), '0'), '.');
        return $text . 'k';
    }
    if (abs($abs - round($abs)) < 0.009) {
        return (string) (int) round($abs);
    }
    return number_format($abs, 0);
}
