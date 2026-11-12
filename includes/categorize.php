<?php

function pattern_matches(string $haystack, string $pattern): bool
{
    $needle = trim(mb_strtolower($pattern));
    if ($needle === '') {
        return false;
    }
    $quoted = preg_quote($needle, '/');
    return (bool) preg_match('/(^|[^a-z0-9])' . $quoted . '([^a-z0-9]|$)/u', $haystack);
}

function extract_receiver(string $description): string
{
    $text = trim(preg_replace('/\s+/', ' ', $description));
    $patterns = [
        '/(?:duitnow|ibg|ibft|funds*to|transfer to|trsf to|payment to|pay to|sent to)\s*[:\-]?\s*(.+)$/i',
        '/(?:from)\s*[:\-]?\s*(.+)$/i',
        '/(?:fpx)\s*[:\-]?\s*(.+)$/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match)) {
            $name = clean_receiver($match[1]);
            if ($name !== '') {
                return $name;
            }
        }
    }

    $cleaned = clean_receiver($text);
    return $cleaned !== '' ? $cleaned : 'Unknown';
}

function clean_receiver(string $name): string
{
    $name = preg_replace('/\b(ref(erence)?|txn|transaction|no\.?|id)[:#]?\s*[a-z0-9\-]+\b/i', ' ', $name);
    $name = preg_replace('/\b\d{6,}\b/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " \t\n\r\0\x0B-:,.");
    if (mb_strlen($name) < 2) {
        return '';
    }
    return mb_substr($name, 0, 80);
}

function is_payment_rail_pattern(string $pattern): bool
{
    return (bool) preg_match('/^(duitnow|ibg|fpx|tng|tngo|mydebit|drc debit|drc credit)$/i', trim($pattern));
}

function categorize_transaction(string $description, string $receiver, string $direction): array
{
    $name = trim($receiver);
    if ($name !== '' && strcasecmp($name, 'Unknown') !== 0) {
        $saved = db()->prepare('SELECT category FROM payee_labels WHERE payee = ?');
        $saved->execute([mb_strtolower($name)]);
        $label = $saved->fetchColumn();
        if ($label) {
            return [(string) $label, 'user'];
        }
    }

    $haystack = mb_strtolower($name !== '' ? $name : $description);
    if ($direction === 'in' && looks_like_scholarship_payee($haystack)) {
        return ['Scholarship', 'auto'];
    }
    $stmt = db()->query('SELECT pattern, category, direction FROM category_rules ORDER BY priority DESC, id ASC');

    foreach ($stmt as $rule) {
        if (is_payment_rail_pattern($rule['pattern'])) {
            continue;
        }
        if ($rule['direction'] !== 'any' && $rule['direction'] !== $direction) {
            continue;
        }
        if ($rule['pattern'] !== '' && pattern_matches($haystack, $rule['pattern'])) {
            return [$rule['category'], 'auto'];
        }
    }

    if ($receiver === '' || strcasecmp($receiver, 'Unknown') === 0) {
        return ['Unknown', 'auto'];
    }

    return ['Unknown', 'auto'];
}

function category_display_name(string $category, string $kind): string
{
    if ($kind === 'in') {
        return match ($category) {
            'Family transfer' => 'From mum / family',
            'Scholarship' => 'Scholarship (SEB)',
            'Transfer' => 'From other people',
            'Transport' => 'Transport refunds',
            default => $category,
        };
    }
    if ($kind === 'out' && $category === 'Family transfer') {
        return 'Sent to family';
    }
    return $category;
}

function cash_in_has_category(array $groups, string $category): bool
{
    foreach ($groups as $group) {
        if (($group['category'] ?? '') === $category) {
            return true;
        }
    }
    return false;
}

function looks_like_scholarship_payee(string $haystack): bool
{
    return (bool) preg_match('/sarawak\s*energy|\bsr\s*energy|energy\s+berhad|\bseb\b|s\.e\.b/i', $haystack);
}

function scholarship_summary(?string $month = null): array
{
    $termStart = setting('term_start', date('Y-01-01'));
    $termEnd = setting('term_end', date('Y-12-31'));
    $expected = (float) setting('expected_term_amount', '0');
    $weekly = (float) setting('weekly_pocket', '250');

    $params = [$termStart, $termEnd];
    $monthSql = '';
    if ($month) {
        $monthSql = ' AND strftime("%Y-%m", txn_date) = ?';
        $params[] = $month;
    }

    $q = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN direction = "in" AND category = "Scholarship" THEN amount ELSE 0 END), 0) AS received,
            COALESCE(SUM(CASE WHEN direction = "out" AND category = "Family transfer" THEN amount ELSE 0 END), 0) AS to_family,
            COALESCE(SUM(CASE WHEN direction = "in" AND category = "Family transfer" THEN amount ELSE 0 END), 0) AS from_family,
            COALESCE(SUM(CASE WHEN direction = "out" AND category != "Family transfer" THEN amount ELSE 0 END), 0) AS spent
         FROM transactions
         WHERE txn_date BETWEEN ? AND ?' . $monthSql
    );
    $q->execute($params);
    $row = $q->fetch() ?: ['received' => 0, 'to_family' => 0, 'from_family' => 0, 'spent' => 0];

    $received = (float) $row['received'];
    $toFamily = (float) $row['to_family'];
    $fromFamily = (float) $row['from_family'];
    $spent = (float) $row['spent'];
    $known = $expected > 0;
    $remaining = $known ? $expected - $spent : null;

    return [
        'term_start' => $termStart,
        'term_end' => $termEnd,
        'expected' => $expected,
        'weekly' => $weekly,
        'received' => $received,
        'to_family' => $toFamily,
        'from_family' => $fromFamily,
        'held_by_family' => max(0, $toFamily - $fromFamily),
        'spent' => $spent,
        'remaining' => $remaining,
        'known' => $known,
    ];
}
