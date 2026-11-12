<?php

function render(string $title, string $body, string $page = 'dashboard'): void
{
    $monthQs = '';
    if (!empty($_GET['month'])) {
        $monthQs = '&month=' . urlencode((string) $_GET['month']);
    }
    $tabs = [
        'dashboard' => ['Types', 'index.php', icon_home()],
        'timeline' => ['Time', 'index.php?page=timeline', icon_cal()],
        'upload' => ['Add', 'index.php?page=upload', icon_plus()],
        'scholarship' => ['Pocket', 'index.php?page=scholarship', icon_wallet()],
        'more' => ['More', 'index.php?page=more', icon_sliders()],
    ];
    if ($monthQs !== '') {
        $tabs['dashboard'][1] = 'index.php?month=' . urlencode((string) $_GET['month']);
        $tabs['timeline'][1] .= $monthQs;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1f2a24">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="crying wallet">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(__DIR__ . '/../assets/app.css') ?>">
</head>
<body class="<?= htmlspecialchars($page) ?>">
    <div class="desk">
        <div class="app">
            <header class="topbar">
                <div>
                    <p class="eyebrow">crying wallet</p>
                    <h1><?= htmlspecialchars($title) ?></h1>
                </div>
                <span class="face" aria-hidden="true">
                    <svg viewBox="0 0 64 64" width="36" height="36">
                        <rect x="8" y="16" width="48" height="36" rx="8" fill="none" stroke="#c8f27a" stroke-width="3"/>
                        <path d="M8 27h48" stroke="#c8f27a" stroke-width="3"/>
                        <circle cx="24" cy="42" r="2.5" fill="#7ec8ff"/>
                        <circle cx="40" cy="42" r="2.5" fill="#7ec8ff"/>
                        <path d="M22 49c2.2 3.2 6.2 3.2 8.4 0" stroke="#7ec8ff" stroke-width="2" fill="none" stroke-linecap="round"/>
                        <path d="M34 49c2.2 3.2 6.2 3.2 8.4 0" stroke="#7ec8ff" stroke-width="2" fill="none" stroke-linecap="round"/>
                    </svg>
                </span>
            </header>
            <main class="screen">
                <?= $body ?>
            </main>
            <nav class="tabbar">
                <?php foreach ($tabs as $key => [$label, $href, $icon]): ?>
                    <a class="tab <?= $page === $key ? 'active' : '' ?> <?= $key === 'upload' ? 'tab-add' : '' ?>" href="<?= htmlspecialchars($href) ?>">
                        <?= $icon ?>
                        <span><?= htmlspecialchars($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
    <script src="assets/app.js?v=<?= (int) filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
</body>
</html>
    <?php
}

function month_options(?string $selected): string
{
    $stmt = db()->query('SELECT DISTINCT strftime("%Y-%m", txn_date) AS m FROM transactions ORDER BY m DESC');
    $months = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$months) {
        $months = [date('Y-m')];
    }
    $html = '';
    foreach ($months as $month) {
        $dt = DateTime::createFromFormat('Y-m', $month);
        $label = $dt ? $dt->format('M Y') : $month;
        $sel = $month === $selected ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($month) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html;
}

function icon_home(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/></svg>';
}

function icon_list(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 7h12M8 12h12M8 17h12M4 7h.01M4 12h.01M4 17h.01"/></svg>';
}

function icon_cal(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>';
}

function icon_plus(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
}

function icon_wallet(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8a2 2 0 0 1 2-2h14v2H5v10h14v-4h-5a2 2 0 1 1 0-4h5V8"/><path d="M19 12h-5"/></svg>';
}

function icon_sliders(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16M8 5v4M16 10v4M11 15v4"/></svg>';
}
