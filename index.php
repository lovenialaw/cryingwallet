<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/parser.php';
require_once __DIR__ . '/includes/layout.php';

db();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$page = $_GET['page'] ?? 'dashboard';
$month = $_GET['month'] ?? setting('last_import_month', current_month());

if ($page === 'rules' && isset($_GET['delete'])) {
    db()->prepare('DELETE FROM category_rules WHERE id = ?')->execute([(int) $_GET['delete']]);
    header('Location: index.php?page=rules');
    exit;
}

if ($page === 'api') {
    handle_api();
    exit;
}

switch ($page) {
    case 'upload':
        render('Add statement', page_upload(), 'upload');
        break;
    case 'timeline':
        render('Timeline', page_timeline($month), 'timeline');
        break;
    case 'transactions':
        header('Location: index.php?page=timeline&month=' . urlencode($month));
        exit;
    case 'scholarship':
        render('Pocket', page_scholarship(), 'scholarship');
        break;
    case 'rules':
        render('Rules', page_rules(), 'more');
        break;
    case 'more':
        render('More', page_more($month), 'more');
        break;
    case 'summary':
        render('Summary', page_summary($month), 'summary');
        break;
    default:
        $dt = DateTime::createFromFormat('Y-m', $month);
        render($dt ? $dt->format('F Y') : 'Types', page_dashboard($month), 'dashboard');
}

function delete_import(int $id): void
{
    if ($id < 1) {
        throw new RuntimeException('That upload is already gone.');
    }
    $stmt = db()->prepare('SELECT * FROM imports WHERE id = ?');
    $stmt->execute([$id]);
    $import = $stmt->fetch();
    if (!$import) {
        throw new RuntimeException('That upload is already gone.');
    }
    $month = (string) ($import['statement_month'] ?? '');
    if ($month === '' && preg_match('/(20\d{2})(0[1-9]|1[0-2])/', (string) $import['filename'], $m)) {
        $month = $m[1] . '-' . $m[2];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $pdo->prepare('DELETE FROM transactions WHERE strftime("%Y-%m", txn_date) = ?')->execute([$month]);
        } else {
            $pdo->prepare('DELETE FROM transactions WHERE import_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM imports WHERE filename = ? OR id = ?')->execute([(string) $import['filename'], $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function uploaded_files(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    if (is_array($files['name'])) {
        $out = [];
        foreach ($files['name'] as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
        return $out;
    }
    $name = trim((string) $files['name']);
    if ($name === '') {
        return [];
    }
    return [[
        'name' => $name,
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
    ]];
}

function current_month(): string
{
    $latest = db()->query('SELECT strftime("%Y-%m", txn_date) FROM transactions ORDER BY txn_date DESC LIMIT 1')->fetchColumn();
    return $latest ?: date('Y-m');
}

function page_dashboard(string $month): string
{
    $stats = month_stats($month);
    $groups = spending_groups($month, 'out');
    $inGroups = spending_groups($month, 'in');
    $balances = month_statement_balances($month);
    $opening = $balances['opening'];
    $closing = $balances['closing'];
    $cashOut = $balances['withdrawal'] !== null ? (float) $balances['withdrawal'] : (float) $stats['cash_out'];
    $cashIn = $balances['deposit'] !== null ? (float) $balances['deposit'] : (float) $stats['cash_in'];
    $computed = $opening !== null ? round($opening + $cashIn - $cashOut, 2) : null;
    $tallyOk = $opening !== null && $closing !== null && $computed !== null && abs($computed - $closing) < 0.06;

    $notice = '';
    if (!empty($_SESSION['flash'])) {
        $notice = (string) $_SESSION['flash'];
        unset($_SESSION['flash']);
    }

    ob_start();
    ?>
    <?php if ($notice): ?><div class="flash"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <div class="hero">
        <div>
            <p class="eyebrow-inline">Month summary</p>
            <p class="lede" style="margin:0">Open a type, tap a name, and set its expense type. That name keeps the type on every day.</p>
        </div>
        <form method="get">
            <select id="month" name="month" onchange="this.form.submit()" aria-label="Month">
                <?= month_options($month) ?>
            </select>
        </form>
    </div>

    <section class="glance glance-balance">
        <?php if ($opening !== null): ?>
            <article>
                <div class="label">Start of month · opening</div>
                <div class="value"><?= money($opening) ?></div>
            </article>
        <?php endif; ?>
        <article>
            <div class="label">Withdrawal · cash out</div>
            <div class="value out"><?= money($cashOut) ?></div>
        </article>
        <article>
            <div class="label">Deposit · cash in</div>
            <div class="value in"><?= money($cashIn) ?></div>
        </article>
        <?php if ($closing !== null): ?>
            <article class="remain">
                <div class="label">End of month · outstanding</div>
                <div class="value"><?= money($closing) ?></div>
            </article>
        <?php endif; ?>
    </section>
    <?php if ($opening !== null && $closing !== null && $computed !== null): ?>
        <p class="hint tally <?= $tallyOk ? 'ok' : '' ?>">
            <?= money($opening) ?> + deposit − withdrawal = <?= money($computed) ?>
            <?= $tallyOk ? ' · matches end of month' : ' · statement end is ' . money($closing) ?>
        </p>
    <?php endif; ?>

    <h2 class="section-title">Money in</h2>
    <?php if (!cash_in_has_category($inGroups, 'Scholarship')): ?>
        <p class="hint">This statement has no Sarawak Energy / scholarship credit. Mum’s weekly money is “From mum / family”. Leftover scholarship only shows after you enter the term amount on Pocket.</p>
    <?php endif; ?>
    <?= category_stack($inGroups, $cashIn, 'in') ?>

    <h2 class="section-title">Money out</h2>
    <?= category_stack($groups, $cashOut, 'out') ?>
    <?php
    return ob_get_clean();
}

function page_timeline(string $month): string
{
    $byDay = timeline_days($month);
    $picked = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : '';
    if ($picked === '' || substr($picked, 0, 7) !== $month) {
        $keys = array_keys($byDay);
        $picked = $keys ? (string) max($keys) : $month . '-01';
    }
    $sheet = $byDay[$picked] ?? [
        'label' => (DateTime::createFromFormat('Y-m-d', $picked) ?: new DateTime())->format('j F Y'),
        'cash_in' => 0.0,
        'cash_out' => 0.0,
        'rows' => [],
    ];

    $start = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: new DateTime('first day of this month');
    $daysInMonth = (int) $start->format('t');
    $pad = (int) $start->format('w');
    $cells = $pad + $daysInMonth;
    $weeks = (int) ceil($cells / 7);

    ob_start();
    ?>
    <div class="hero">
        <p class="lede" style="margin:0">Tap a date, then tap a name to change its type.</p>
        <form method="get">
            <input type="hidden" name="page" value="timeline">
            <select name="month" onchange="this.form.submit()" aria-label="Month">
                <?= month_options($month) ?>
            </select>
        </form>
    </div>

    <div class="cal">
        <div class="cal-weekdays" aria-hidden="true">
            <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
        </div>
        <div class="cal-grid">
            <?php for ($i = 0; $i < $weeks * 7; $i++): ?>
                <?php
                $dayNum = $i - $pad + 1;
                if ($dayNum < 1 || $dayNum > $daysInMonth) {
                    echo '<div class="cal-cell pad"></div>';
                    continue;
                }
                $iso = sprintf('%s-%02d', $month, $dayNum);
                $info = $byDay[$iso] ?? null;
                $cashIn = $info ? (float) $info['cash_in'] : 0.0;
                $cashOut = $info ? (float) $info['cash_out'] : 0.0;
                $busy = $cashIn >= 0.01 || $cashOut >= 0.01;
                $cls = 'cal-cell' . ($busy ? ' on' : ' idle') . ($iso === $picked ? ' sel' : '');
                $href = 'index.php?page=timeline&month=' . urlencode($month) . '&day=' . urlencode($iso);
                ?>
                <a class="<?= $cls ?>" href="<?= htmlspecialchars($href) ?>">
                    <span class="cal-n"><?= $dayNum ?></span>
                    <?php if ($cashOut >= 0.01): ?>
                        <span class="cal-out">−<?= htmlspecialchars(money_compact($cashOut)) ?></span>
                    <?php endif; ?>
                    <?php if ($cashIn >= 0.01): ?>
                        <span class="cal-in">+<?= htmlspecialchars(money_compact($cashIn)) ?></span>
                    <?php endif; ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>

    <section class="day-sheet" id="day">
        <h2><?= htmlspecialchars($sheet['label']) ?></h2>
        <p class="day-sheet-sum">
            <span class="out">Out <?= money((float) $sheet['cash_out']) ?></span>
            <span class="in">In <?= money((float) $sheet['cash_in']) ?></span>
        </p>
        <?php if (!$sheet['rows']): ?>
            <p class="hint">Nothing on this date.</p>
        <?php else: ?>
            <?php
            $cats = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($sheet['rows'] as $row):
                $who = $row['receiver'] ?: 'Unknown';
                $dir = (string) $row['direction'];
                $cat = (string) $row['category'];
            ?>
                <details class="tl-row">
                    <summary>
                        <div class="tl-who"><?= htmlspecialchars($who) ?></div>
                        <div class="tl-amt <?= htmlspecialchars($dir) ?>"><?= $dir === 'out' ? '−' : '+' ?><?= money((float) $row['amount']) ?></div>
                        <div class="tl-cat"><?= htmlspecialchars(category_display_name($cat, $dir)) ?></div>
                    </summary>
                    <div class="tl-edit">
                        <label for="cat-<?= (int) $row['id'] ?>">Type for this name</label>
                        <select id="cat-<?= (int) $row['id'] ?>" class="cat-select" data-recategorize data-apply-payee data-id="<?= (int) $row['id'] ?>" data-payee="<?= htmlspecialchars($who) ?>" data-direction="<?= htmlspecialchars($dir) ?>">
                            <?php foreach ($cats as $name): ?>
                                <option value="<?= htmlspecialchars($name) ?>" <?= $cat === $name ? 'selected' : '' ?>><?= htmlspecialchars(category_display_name($name, $dir)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint">Saves this name for every day, every month, and the next statement.</p>
                        <span class="saved"></span>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

function page_more(string $month): string
{
    $flashRelabel = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relabel_ai') {
        try {
            set_time_limit(60);
            $n = relabel_month_receivers($month);
            $flashRelabel = "AI labelled {$n} receivers for this month.";
        } catch (Throwable $e) {
            $flashRelabel = $e->getMessage();
        }
    }
    ob_start();
    ?>
    <?php if ($flashRelabel): ?><div class="flash"><?= htmlspecialchars($flashRelabel) ?></div><?php endif; ?>
    <a class="menu-link" href="index.php?page=summary&month=<?= urlencode($month) ?>">Save month summary (PDF)</a>
    <a class="menu-link" href="index.php?page=rules">Receiver matching rules</a>
    <form method="post" action="index.php?page=more&month=<?= urlencode($month) ?>">
        <input type="hidden" name="action" value="relabel_ai">
        <button class="btn" type="submit">Re-label this month with AI</button>
    </form>
    <p class="hint" style="margin-top:16px">On the summary screen, use your browser Print → Save as PDF.</p>
    <?php
    return ob_get_clean();
}

function page_summary(string $month): string
{
    $stats = month_stats($month);
    $groups = spending_groups($month, 'out');
    $dt = DateTime::createFromFormat('Y-m', $month);
    $label = $dt ? $dt->format('F Y') : $month;
    ob_start();
    ?>
    <div class="print-actions">
        <button class="btn" type="button" onclick="window.print()">Save as PDF</button>
        <a class="btn ghost" href="index.php?month=<?= urlencode($month) ?>">Back</a>
    </div>
    <article class="print-sheet">
        <p class="eyebrow-inline">crying wallet</p>
        <h2><?= htmlspecialchars($label) ?> spending</h2>
        <p>Cash out <?= money((float) $stats['cash_out']) ?> · Cash in <?= money((float) $stats['cash_in']) ?></p>
        <?php foreach ($groups as $group): ?>
            <h3><?= htmlspecialchars($group['category']) ?> — <?= money((float) $group['total']) ?></h3>
            <ul>
                <?php foreach ($group['payees'] as $payee): ?>
                    <li><?= htmlspecialchars($payee['name']) ?> · <?= money((float) $payee['total']) ?> · <?= (int) $payee['count'] ?>×</li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </article>
    <?php
    return ob_get_clean();
}

function timeline_days(string $month): array
{
    $stmt = db()->prepare('SELECT * FROM transactions WHERE strftime("%Y-%m", txn_date) = ? ORDER BY txn_date ASC, id ASC');
    $stmt->execute([$month]);
    $days = [];
    foreach ($stmt as $row) {
        $date = $row['txn_date'];
        if (!isset($days[$date])) {
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            $days[$date] = [
                'label' => $dt ? $dt->format('j F Y') : $date,
                'cash_in' => 0.0,
                'cash_out' => 0.0,
                'rows' => [],
            ];
        }
        $amt = (float) $row['amount'];
        if ($row['direction'] === 'in') {
            $days[$date]['cash_in'] += $amt;
        } else {
            $days[$date]['cash_out'] += $amt;
        }
        $days[$date]['rows'][] = $row;
    }
    return $days;
}

function page_upload(): string
{
    $flash = '';
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_import') {
        $id = (int) ($_POST['import_id'] ?? 0);
        try {
            delete_import($id);
            $_SESSION['flash'] = 'Statement removed from Types, Time, and this list.';
        } catch (Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        header('Location: index.php?page=upload');
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_key') {
        $typed = trim((string) ($_POST['gemini_api_key'] ?? ''));
        if ($typed === '') {
            $error = 'Paste a key first, or use Remove key.';
        } else {
            save_gemini_api_key($typed);
            $flash = 'Key saved on this computer. You will not be asked again when you upload.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_key') {
        save_gemini_api_key('');
        $flash = 'Key removed. Text PDFs still work; scans need a key.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['statement'])) {
        $batch = uploaded_files($_FILES['statement']);
        if (!$batch) {
            $error = 'Choose one or more bank PDFs or CSVs.';
        } else {
            set_time_limit(30 + 60 * count($batch));
            $ok = [];
            $fail = [];
            $focusMonth = '';
            foreach ($batch as $file) {
                $name = $file['name'];
                try {
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Could not read this file.');
                    }
                    $parsed = parse_bank_file($file['tmp_name'], $name);
                    $result = save_import(
                        $name,
                        $parsed['transactions'],
                        isset($parsed['opening_balance']) && $parsed['opening_balance'] !== null ? (float) $parsed['opening_balance'] : null,
                        isset($parsed['closing_balance']) && $parsed['closing_balance'] !== null ? (float) $parsed['closing_balance'] : null,
                        isset($parsed['total_withdrawal']) && $parsed['total_withdrawal'] !== null ? (float) $parsed['total_withdrawal'] : null,
                        isset($parsed['total_deposit']) && $parsed['total_deposit'] !== null ? (float) $parsed['total_deposit'] : null
                    );
                    $focusMonth = statement_month($parsed);
                    set_setting('last_import_month', $focusMonth);
                    $label = DateTime::createFromFormat('Y-m', $focusMonth);
                    $monthName = $label ? $label->format('M Y') : $focusMonth;
                    $ok[] = $name . ' · ' . $monthName . ' · ' . $result['added'] . ' rows';
                    try {
                        relabel_month_receivers($focusMonth);
                    } catch (Throwable $e) {
                        // rows are saved even if AI labelling is busy
                    }
                } catch (Throwable $e) {
                    $fail[] = $name . ' · ' . $e->getMessage();
                }
            }
            $parts = [];
            if ($ok) {
                $parts[] = count($ok) === 1 ? $ok[0] : (count($ok) . ' statements read: ' . implode('; ', $ok));
            }
            if ($fail) {
                $parts[] = 'Could not read: ' . implode('; ', $fail);
            }
            $_SESSION['flash'] = implode(' ', $parts);
            header('Location: index.php?page=upload');
            exit;
        }
    }

    $hasKey = gemini_api_key() !== '';
    if (!empty($_SESSION['flash'])) {
        $flash = (string) $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
    $imports = db()->query('
        SELECT * FROM imports
        WHERE id IN (SELECT MAX(id) FROM imports GROUP BY filename)
        ORDER BY COALESCE(statement_month, "") DESC, imported_at DESC, id DESC
    ')->fetchAll();
    ob_start();
    ?>
    <p class="lede">Pick one statement or several at once. Each file’s month lands in Types, Time, and this list.</p>
    <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form class="drop" method="post" enctype="multipart/form-data">
        <p>Bank PDFs or CSVs</p>
        <input type="file" name="statement[]" accept=".pdf,.csv,application/pdf,text/csv" multiple required>
        <p><button class="btn" type="submit">Read statements</button></p>
        <p class="hint"><?= $hasKey ? 'AI is already saved on this computer. Select June through October together if you like.' : 'Text PDFs work now. Scanned statements need a one-time AI key below.' ?></p>
    </form>

    <h2 class="section-title">Uploaded</h2>
    <?php if (!$imports): ?>
        <p class="hint">Nothing here yet. The files you read will show up with their month and how many rows landed.</p>
    <?php else: ?>
        <div class="import-list">
            <?php foreach ($imports as $import): ?>
                <?php
                $month = (string) ($import['statement_month'] ?? '');
                if ($month === '' && preg_match('/(20\d{2})(0[1-9]|1[0-2])/', (string) $import['filename'], $m)) {
                    $month = $m[1] . '-' . $m[2];
                }
                $dt = $month !== '' ? DateTime::createFromFormat('Y-m', $month) : null;
                $monthLabel = $dt ? $dt->format('M Y') : 'Month unknown';
                $when = strtotime((string) $import['imported_at']);
                $whenLabel = $when ? date('j M Y, g:ia', $when) : (string) $import['imported_at'];
                ?>
                <article class="import-card">
                    <div>
                        <strong><?= htmlspecialchars($monthLabel) ?></strong>
                        <div class="hint"><?= htmlspecialchars((string) $import['filename']) ?></div>
                        <div class="hint"><?= (int) $import['added_count'] ?> rows · <?= htmlspecialchars($whenLabel) ?></div>
                    </div>
                    <div class="import-actions">
                        <?php if ($month !== ''): ?>
                            <a href="index.php?month=<?= urlencode($month) ?>">Types</a>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Remove this statement and its rows from the app?');">
                            <input type="hidden" name="action" value="delete_import">
                            <input type="hidden" name="import_id" value="<?= (int) $import['id'] ?>">
                            <button class="link-btn" type="submit">Delete</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($hasKey): ?>
        <div class="card" style="margin-top:12px">
            <p class="hint" style="margin:0">AI reader is on. The key stays here until you remove it — not typed on each upload.</p>
            <form method="post" style="margin-top:10px">
                <input type="hidden" name="action" value="remove_key">
                <button class="btn ghost" type="submit">Remove saved key</button>
            </form>
        </div>
    <?php else: ?>
        <form class="card stack" method="post" style="margin-top:12px">
            <input type="hidden" name="action" value="save_key">
            <label>One-time Gemini key
                <input type="password" name="gemini_api_key" placeholder="Paste once from Google AI Studio" autocomplete="off">
            </label>
            <p class="hint">Get a free key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>. Save it once; the app keeps it on this PC and uses it automatically. It is not put in the public code (a shared engraved key would leak and get cut off).</p>
            <button class="btn ghost" type="submit">Save key on this computer</button>
        </form>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function page_transactions(string $month): string
{
    $direction = $_GET['direction'] ?? '';
    $category = $_GET['category'] ?? '';
    $q = trim((string) ($_GET['q'] ?? ''));

    $sql = 'SELECT * FROM transactions WHERE strftime("%Y-%m", txn_date) = ?';
    $params = [$month];
    if ($direction === 'in' || $direction === 'out') {
        $sql .= ' AND direction = ?';
        $params[] = $direction;
    }
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    if ($q !== '') {
        $sql .= ' AND (description LIKE ? OR receiver LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY txn_date DESC, id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $cats = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'relabel_ai') {
        try {
            set_time_limit(60);
            $n = relabel_month_receivers($month);
            $flashRelabel = "AI labelled {$n} receivers from Additional Details for this month.";
        } catch (Throwable $e) {
            $flashRelabel = $e->getMessage();
        }
    }

    $groups = spending_groups($month, $direction === 'in' ? 'in' : 'out');
    if ($category !== '') {
        $groups = array_values(array_filter($groups, static fn($g) => $g['category'] === $category));
    }

    ob_start();
    ?>
    <p class="lede">Totals by type. Open a type to see who received the money.</p>
    <?php if (!empty($flashRelabel)): ?><div class="flash"><?= htmlspecialchars($flashRelabel) ?></div><?php endif; ?>
    <form method="post" action="index.php?page=transactions&month=<?= urlencode($month) ?>" style="margin-bottom:12px">
        <input type="hidden" name="action" value="relabel_ai">
        <button class="btn" type="submit">Re-label this month with AI</button>
    </form>
    <form class="filters" method="get">
        <input type="hidden" name="page" value="transactions">
        <select name="month" onchange="this.form.submit()"><?= month_options($month) ?></select>
        <select name="direction" onchange="this.form.submit()">
            <option value="out" <?= $direction !== 'in' ? 'selected' : '' ?>>Cash out</option>
            <option value="in" <?= $direction === 'in' ? 'selected' : '' ?>>Cash in</option>
        </select>
        <select name="category" onchange="this.form.submit()">
            <option value="">All types</option>
            <?php foreach ($cats as $name): ?>
                <option value="<?= htmlspecialchars($name) ?>" <?= $category === $name ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="wide" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search receiver">
    </form>
    <?php if ($q !== ''): ?>
        <?= transactions_table($rows, true) ?>
    <?php elseif (!$groups): ?>
        <p class="hint">Nothing in this month yet.</p>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <details class="cat-card cat-card-wide" <?= $category !== '' ? 'open' : '' ?>>
                <summary>
                    <span class="cat-name"><?= htmlspecialchars($group['category']) ?></span>
                    <span class="cat-amt"><?= money((float) $group['total']) ?></span>
                    <span class="cat-sub"><?= (int) $group['count'] ?> payments</span>
                </summary>
                <ul class="payee-roll">
                    <?php foreach ($group['payees'] as $payee): ?>
                        <li>
                            <span><?= htmlspecialchars($payee['name']) ?></span>
                            <strong><?= money((float) $payee['total']) ?></strong>
                            <em><?= (int) $payee['count'] === 1 ? '1 time' : ((int) $payee['count'] . ' times') ?></em>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <details class="fix-list">
                    <summary>Fix a label</summary>
                    <?= transactions_table($group['rows'], true) ?>
                </details>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function page_scholarship(): string
{
    $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        set_setting('sponsor_name', trim((string) ($_POST['sponsor_name'] ?? '')));
        set_setting('family_names', trim((string) ($_POST['family_names'] ?? '')));
        set_setting('weekly_pocket', (string) max(0, (float) ($_POST['weekly_pocket'] ?? 0)));
        set_setting('term_start', (string) ($_POST['term_start'] ?? date('Y-01-01')));
        set_setting('term_end', (string) ($_POST['term_end'] ?? date('Y-12-31')));
        set_setting('expected_term_amount', (string) max(0, (float) ($_POST['expected_term_amount'] ?? 0)));
        sync_family_and_sponsor_rules();
        $saved = true;
    }

    $sum = scholarship_summary();
    ob_start();
    ?>
    <p class="lede">A June statement cannot show how much scholarship is left unless you enter the term allowance. Money from mum is family in, not scholarship.</p>
    <?php if ($saved): ?><div class="flash">Saved. Incoming sponsor names and family names were added to the matching rules.</div><?php endif; ?>

    <section class="cards">
        <article class="card in"><div class="label">SEB / scholarship in</div><div class="value"><?= money((float) $sum['received']) ?></div></article>
        <article class="card in"><div class="label">From family</div><div class="value"><?= money((float) $sum['from_family']) ?></div></article>
        <article class="card"><div class="label">With family</div><div class="value"><?= money((float) $sum['held_by_family']) ?></div></article>
        <?php if ($sum['known']): ?>
            <article class="card remain"><div class="label">Left this term</div><div class="value"><?= money((float) $sum['remaining']) ?></div></article>
        <?php else: ?>
            <article class="card"><div class="label">Left this term</div><div class="value">Set amount</div></article>
        <?php endif; ?>
    </section>

    <form class="card stack" method="post">
        <label>Sponsor name (as it appears on the statement)
            <input name="sponsor_name" value="<?= htmlspecialchars(setting('sponsor_name')) ?>" required>
        </label>
        <label>Family names / nicknames, comma separated
            <input name="family_names" value="<?= htmlspecialchars(setting('family_names')) ?>">
        </label>
        <label>Term start
            <input type="date" name="term_start" value="<?= htmlspecialchars($sum['term_start']) ?>">
        </label>
        <label>Term end
            <input type="date" name="term_end" value="<?= htmlspecialchars($sum['term_end']) ?>">
        </label>
        <label>Expected term amount (optional)
            <input type="number" step="0.01" name="expected_term_amount" value="<?= htmlspecialchars((string) $sum['expected']) ?>">
        </label>
        <label>Weekly pocket
            <input type="number" step="0.01" name="weekly_pocket" value="<?= htmlspecialchars((string) $sum['weekly']) ?>">
        </label>
        <p class="hint">Fill the term allowance (what SEB pays this term). Then leftover = that amount minus spending. Weekly money from mum should be labelled Family transfer, not Scholarship.</p>
        <button class="btn" type="submit">Save scholarship settings</button>
    </form>
    <?php
    return ob_get_clean();
}

function page_rules(): string
{
    $rules = db()->query('SELECT * FROM category_rules ORDER BY priority DESC, id ASC')->fetchAll();
    $cats = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

    ob_start();
    ?>
    <p class="lede">Match a word in the receiver name to a type. Example: a stall name that is always food.</p>

    <form class="card stack" data-ajax-category>
        <input name="category_name" placeholder="new type, e.g. Hostel">
        <button class="btn ghost" type="submit">Add spending type</button>
    </form>

    <form class="card stack" data-ajax-rule style="margin-top:12px">
        <input name="pattern" placeholder="word in the receiver name" required>
        <select name="category">
            <?php foreach ($cats as $name): ?>
                <option><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="direction">
            <option value="any">in or out</option>
            <option value="out">cash out</option>
            <option value="in">cash in</option>
        </select>
        <input name="priority" type="number" value="160" title="Higher wins">
        <button class="btn" type="submit">Add rule</button>
    </form>

    <article class="card" style="margin-top:12px">
        <?php foreach ($rules as $rule): ?>
            <div class="rule">
                <div>
                    <strong><?= htmlspecialchars($rule['pattern']) ?></strong>
                    <div class="hint">→ <?= htmlspecialchars($rule['category']) ?> · <?= htmlspecialchars($rule['direction']) ?></div>
                </div>
                <a href="index.php?page=rules&delete=<?= (int) $rule['id'] ?>">remove</a>
            </div>
        <?php endforeach; ?>
    </article>
    <?php
    return ob_get_clean();
}

function transactions_table(array $rows, bool $editable): string
{
    $cats = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    ob_start();
    if (!$rows) {
        echo '<p class="hint">No records yet. Tap Add to upload a statement.</p>';
        return ob_get_clean();
    }
    ?>
    <div class="txn-list">
        <?php foreach ($rows as $row): ?>
            <article class="txn">
                <div class="who"><?= htmlspecialchars($row['receiver'] ?: 'Unknown') ?></div>
                <div class="amt <?= htmlspecialchars($row['direction']) ?>"><?= $row['direction'] === 'out' ? '−' : '+' ?><?= money((float) $row['amount']) ?></div>
                <div class="meta">
                    <?= htmlspecialchars($row['txn_date']) ?>
                    · <span class="pill <?= htmlspecialchars($row['direction']) ?>"><?= $row['direction'] === 'in' ? 'in' : 'out' ?></span>
                    · <?= htmlspecialchars($row['category']) ?>
                </div>
                <?php if ($editable): ?>
                    <div class="cat">
                        <select class="cat-select" data-recategorize data-id="<?= (int) $row['id'] ?>">
                            <?php foreach ($cats as $name): ?>
                                <option value="<?= htmlspecialchars($name) ?>" <?= $row['category'] === $name ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="saved"></span>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function category_stack(array $groups, float $total, string $kind): string
{
    if (!$groups) {
        return '<p class="hint">None this month.</p>';
    }
    $cats = db()->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    ob_start();
    echo '<div class="cat-grid">';
    foreach ($groups as $group) {
        $pct = $total > 0 ? round(((float) $group['total'] / $total) * 100) : 0;
        $names = array_slice(array_column($group['payees'], 'name'), 0, 3);
        $share = $kind === 'in' ? '% of cash in' : '% of cash out';
        $count = (int) $group['count'];
        $unit = $kind === 'in'
            ? ($count === 1 ? 'credit' : 'credits')
            : ($count === 1 ? 'payment' : 'payments');
        $catKey = (string) $group['category'];
        $label = category_display_name($catKey, $kind);
        ?>
        <details class="cat-card" data-category="<?= htmlspecialchars($catKey) ?>">
            <summary>
                <span class="cat-name"><?= htmlspecialchars($label) ?></span>
                <span class="cat-amt"><?= money((float) $group['total']) ?></span>
                <span class="cat-sub"><?= $count ?> <?= $unit ?> · <?= $pct ?><?= $share ?></span>
                <span class="cat-payees"><?= htmlspecialchars(implode(' · ', $names)) ?></span>
            </summary>
            <ul class="payee-roll">
                <?php foreach ($group['payees'] as $payee): ?>
                    <?php
                    $who = (string) $payee['name'];
                    $payeeId = (int) ($payee['id'] ?? 0);
                    $dir = (string) ($payee['direction'] ?? $kind);
                    ?>
                    <li>
                        <details class="payee-row">
                            <summary>
                                <span class="tl-who"><?= htmlspecialchars($who) ?></span>
                                <strong><?= money((float) $payee['total']) ?></strong>
                                <em><?= (int) $payee['count'] === 1 ? '1 time' : ((int) $payee['count'] . ' times') ?></em>
                            </summary>
                            <div class="tl-edit">
                                <label for="dash-cat-<?= $payeeId ?>">Type for this name</label>
                                <select id="dash-cat-<?= $payeeId ?>" class="cat-select" data-recategorize data-apply-payee data-id="<?= $payeeId ?>" data-payee="<?= htmlspecialchars($who) ?>" data-direction="<?= htmlspecialchars($dir) ?>">
                                    <?php foreach ($cats as $name): ?>
                                        <option value="<?= htmlspecialchars($name) ?>" <?= $catKey === $name ? 'selected' : '' ?>><?= htmlspecialchars(category_display_name($name, $dir)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="hint">Saves this name for every day, every month, and the next statement.</p>
                                <span class="saved"></span>
                            </div>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }
    echo '</div>';
    return ob_get_clean();
}

function spending_groups(string $month, string $direction = 'out'): array
{
    $stmt = db()->prepare('
        SELECT t.*, COALESCE(c.color, "#1f2a24") AS color
        FROM transactions t
        LEFT JOIN categories c ON c.name = t.category
        WHERE strftime("%Y-%m", t.txn_date) = ? AND t.direction = ?
        ORDER BY t.amount DESC
    ');
    $stmt->execute([$month, $direction]);
    $rows = $stmt->fetchAll();
    $groups = [];
    foreach ($rows as $row) {
        $cat = $row['category'] ?: 'Unknown';
        if (!isset($groups[$cat])) {
            $groups[$cat] = [
                'category' => $cat,
                'color' => $row['color'],
                'total' => 0.0,
                'count' => 0,
                'payees' => [],
                'rows' => [],
            ];
        }
        $groups[$cat]['total'] += (float) $row['amount'];
        $groups[$cat]['count']++;
        $groups[$cat]['rows'][] = $row;
        $payee = trim((string) ($row['receiver'] ?: $row['description'] ?: 'Unknown'));
        if (!isset($groups[$cat]['payees'][$payee])) {
            $groups[$cat]['payees'][$payee] = [
                'name' => $payee,
                'total' => 0.0,
                'count' => 0,
                'id' => (int) $row['id'],
                'direction' => (string) $row['direction'],
            ];
        }
        $groups[$cat]['payees'][$payee]['total'] += (float) $row['amount'];
        $groups[$cat]['payees'][$payee]['count']++;
    }

    $order = $direction === 'in'
        ? ['Scholarship', 'Family transfer', 'Transfer', 'Unknown', 'Transport', 'Cash', 'Bills']
        : ['Food', 'Transport', 'Family transfer', 'Exam', 'Online shopping', 'Bills', 'Transfer', 'Cash', 'Scholarship', 'Unknown'];
    foreach ($groups as &$group) {
        $payees = array_values($group['payees']);
        usort($payees, static fn($a, $b) => $b['total'] <=> $a['total']);
        $group['payees'] = $payees;
    }
    unset($group);

    uksort($groups, static function ($a, $b) use ($groups, $order) {
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);
        $ia = $ia === false ? 50 : $ia;
        $ib = $ib === false ? 50 : $ib;
        if ($ia === $ib) {
            return $groups[$b]['total'] <=> $groups[$a]['total'];
        }
        return $ia <=> $ib;
    });

    return array_values($groups);
}

function month_statement_balances(string $month): array
{
    $stmt = db()->prepare('
        SELECT opening_balance, closing_balance, total_withdrawal, total_deposit
        FROM imports
        WHERE statement_month = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$month]);
    $row = $stmt->fetch();
    if (!$row) {
        $stmt = db()->prepare('
            SELECT opening_balance, closing_balance, total_withdrawal, total_deposit
            FROM imports
            WHERE filename LIKE ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $compact = str_replace('-', '', $month);
        $stmt->execute(['%' . $compact . '%']);
        $row = $stmt->fetch();
    }
    $opening = isset($row['opening_balance']) && $row['opening_balance'] !== null && $row['opening_balance'] !== ''
        ? (float) $row['opening_balance']
        : null;
    $closing = isset($row['closing_balance']) && $row['closing_balance'] !== null && $row['closing_balance'] !== ''
        ? (float) $row['closing_balance']
        : null;
    $withdrawal = isset($row['total_withdrawal']) && $row['total_withdrawal'] !== null && $row['total_withdrawal'] !== ''
        ? (float) $row['total_withdrawal']
        : null;
    $deposit = isset($row['total_deposit']) && $row['total_deposit'] !== null && $row['total_deposit'] !== ''
        ? (float) $row['total_deposit']
        : null;
    return ['opening' => $opening, 'closing' => $closing, 'withdrawal' => $withdrawal, 'deposit' => $deposit];
}

function month_stats(string $month): array
{
    $stmt = db()->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS cash_out
        FROM transactions
        WHERE strftime("%Y-%m", txn_date) = ?
    ');
    $stmt->execute([$month]);
    return $stmt->fetch() ?: ['cash_in' => 0, 'cash_out' => 0];
}

function category_breakdown(string $month): array
{
    $stmt = db()->prepare('
        SELECT t.category, COALESCE(c.color, "#1f2a24") AS color, SUM(t.amount) AS total
        FROM transactions t
        LEFT JOIN categories c ON c.name = t.category
        WHERE strftime("%Y-%m", txn_date) = ? AND t.direction = "out"
        GROUP BY t.category
        ORDER BY total DESC
    ');
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

function sync_family_and_sponsor_rules(): void
{
    $sponsor = mb_strtolower(trim(setting('sponsor_name')));
    if ($sponsor !== '') {
        $exists = db()->prepare('SELECT id FROM category_rules WHERE pattern = ? AND category = "Scholarship"');
        $exists->execute([$sponsor]);
        if (!$exists->fetch()) {
            db()->prepare('INSERT INTO category_rules (pattern, category, direction, priority) VALUES (?, "Scholarship", "in", 210)')
                ->execute([$sponsor]);
        }
    }

    $names = array_filter(array_map('trim', explode(',', setting('family_names'))));
    foreach ($names as $name) {
        $name = mb_strtolower($name);
        if ($name === '') {
            continue;
        }
        $exists = db()->prepare('SELECT id FROM category_rules WHERE pattern = ? AND category = "Family transfer"');
        $exists->execute([$name]);
        if (!$exists->fetch()) {
            db()->prepare('INSERT INTO category_rules (pattern, category, direction, priority) VALUES (?, "Family transfer", "any", 165)')
                ->execute([$name]);
        }
    }
}

function handle_api(): void
{
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

    try {
        if ($action === 'recategorize') {
            $id = (int) ($input['id'] ?? 0);
            $category = trim((string) ($input['category'] ?? ''));
            $exists = db()->prepare('SELECT name FROM categories WHERE name = ?');
            $exists->execute([$category]);
            if (!$id || !$exists->fetch()) {
                throw new RuntimeException('Unknown category');
            }
            $txn = db()->prepare('SELECT id, receiver, direction FROM transactions WHERE id = ?');
            $txn->execute([$id]);
            $row = $txn->fetch();
            if (!$row) {
                throw new RuntimeException('Unknown row');
            }
            $payee = trim((string) $row['receiver']);
            $applyName = $payee !== '' && strcasecmp($payee, 'Unknown') !== 0;
            if ($applyName) {
                $same = db()->prepare('
                    UPDATE transactions
                    SET category = ?, category_source = "user"
                    WHERE trim(receiver) COLLATE NOCASE = trim(?) COLLATE NOCASE
                ');
                $same->execute([$category, $payee]);
                $updated = max(1, $same->rowCount());
                remember_payee_label($payee, $category);
                $pattern = mb_strtolower($payee);
                $rule = db()->prepare('SELECT id FROM category_rules WHERE pattern = ? LIMIT 1');
                $rule->execute([$pattern]);
                $ruleId = $rule->fetchColumn();
                if ($ruleId) {
                    db()->prepare('UPDATE category_rules SET category = ?, direction = ? WHERE id = ?')
                        ->execute([$category, 'any', $ruleId]);
                } else {
                    db()->prepare('INSERT INTO category_rules (pattern, category, direction, priority) VALUES (?, ?, ?, 250)')
                        ->execute([$pattern, $category, 'any']);
                }
            } else {
                db()->prepare('UPDATE transactions SET category = ?, category_source = "user" WHERE id = ?')
                    ->execute([$category, $id]);
                $updated = 1;
            }
            echo json_encode([
                'ok' => true,
                'updated' => $updated,
                'label' => category_display_name($category, (string) $row['direction']),
                'category' => $category,
            ]);
            return;
        }

        if ($action === 'add_category') {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Category name required');
            }
            db()->prepare('INSERT OR IGNORE INTO categories (name, kind, color) VALUES (?, "expense", "#57534e")')
                ->execute([$name]);
            echo json_encode(['ok' => true]);
            return;
        }

        if ($action === 'add_rule') {
            $pattern = mb_strtolower(trim((string) ($input['pattern'] ?? '')));
            $category = trim((string) ($input['category'] ?? ''));
            $direction = in_array($input['direction'] ?? 'any', ['in', 'out', 'any'], true) ? $input['direction'] : 'any';
            $priority = (int) ($input['priority'] ?? 150);
            if ($pattern === '' || $category === '') {
                throw new RuntimeException('Pattern and category required');
            }
            db()->prepare('INSERT INTO category_rules (pattern, category, direction, priority) VALUES (?, ?, ?, ?)')
                ->execute([$pattern, $category, $direction, $priority]);
            recategorize_auto_rows();
            echo json_encode(['ok' => true]);
            return;
        }

        throw new RuntimeException('Unknown action');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function recategorize_auto_rows(): void
{
    $rows = db()->query('SELECT id, description, receiver, direction FROM transactions WHERE category_source = "auto"')->fetchAll();
    $upd = db()->prepare('UPDATE transactions SET category = ? WHERE id = ?');
    foreach ($rows as $row) {
        [$category] = categorize_transaction($row['description'], $row['receiver'], $row['direction']);
        $upd->execute([$category, $row['id']]);
    }
}
