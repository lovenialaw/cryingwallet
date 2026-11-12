<?php

require_once __DIR__ . '/categorize.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/ai.php';

function statement_month(array $parsed): string
{
    if (!empty($parsed['period_start'])) {
        return substr((string) $parsed['period_start'], 0, 7);
    }
    $counts = [];
    foreach ($parsed['transactions'] ?? [] as $txn) {
        $month = substr((string) ($txn['txn_date'] ?? ''), 0, 7);
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $counts[$month] = ($counts[$month] ?? 0) + 1;
        }
    }
    if (!$counts) {
        return date('Y-m');
    }
    arsort($counts);
    return (string) array_key_first($counts);
}

function parse_bank_file(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return [
            'transactions' => parse_bank_csv($path),
            'period_start' => null,
            'period_end' => null,
            'engine' => 'csv',
        ];
    }
    if ($ext === 'pdf') {
        return parse_bank_pdf($path, $filename);
    }
    throw new RuntimeException('Upload a bank PDF or CSV.');
}

function parse_bank_pdf(string $path, string $filename): array
{
    $text = '';
    $extractError = '';
    $aiError = '';
    try {
        $text = extract_pdf_text($path);
    } catch (Throwable $e) {
        $text = '';
        $extractError = $e->getMessage();
    }

    $local = parse_statement_text($text);
    $localTxns = hydrate_extracted_rows($local['rows']);
    if (count($localTxns) >= 3) {
        $localTxns = classify_receivers_with_ai($localTxns);
        return [
            'transactions' => $localTxns,
            'period_start' => $local['period_start'],
            'period_end' => $local['period_end'],
            'opening_balance' => $local['opening_balance'] ?? null,
            'closing_balance' => $local['closing_balance'] ?? null,
            'total_withdrawal' => $local['total_withdrawal'] ?? null,
            'total_deposit' => $local['total_deposit'] ?? null,
            'engine' => 'pdf-text',
        ];
    }

    if (gemini_api_key() !== '') {
        try {
            $ai = gemini_extract_statement($path, $filename);
            $txns = hydrate_extracted_rows($ai['transactions'] ?? []);
            if ($txns) {
                $txns = classify_receivers_with_ai($txns);
                return [
                    'transactions' => $txns,
                    'period_start' => $ai['period_start'] ?? null,
                    'period_end' => $ai['period_end'] ?? null,
                    'opening_balance' => $ai['opening_balance'] ?? $local['opening_balance'] ?? null,
                    'closing_balance' => $ai['closing_balance'] ?? $local['closing_balance'] ?? null,
                    'total_withdrawal' => $ai['total_withdrawal'] ?? $local['total_withdrawal'] ?? null,
                    'total_deposit' => $ai['total_deposit'] ?? $local['total_deposit'] ?? null,
                    'engine' => $ai['engine'] ?? 'gemini',
                ];
            }
        } catch (Throwable $e) {
            $aiError = $e->getMessage();
        }
    }

    if ($localTxns) {
        $localTxns = classify_receivers_with_ai($localTxns);
        return [
            'transactions' => $localTxns,
            'period_start' => $local['period_start'],
            'period_end' => $local['period_end'],
            'opening_balance' => $local['opening_balance'] ?? null,
            'closing_balance' => $local['closing_balance'] ?? null,
            'total_withdrawal' => $local['total_withdrawal'] ?? null,
            'total_deposit' => $local['total_deposit'] ?? null,
            'engine' => 'pdf-text',
        ];
    }

    if (mb_strlen($text) < 80 && gemini_api_key() === '') {
        throw new RuntimeException('This PDF looks scanned or image-based. Add a free Gemini API key on this screen so the app can read the month and each row.');
    }
    if (!empty($aiError)) {
        throw new RuntimeException('Could not read this PDF with AI. ' . $aiError);
    }
    if (!empty($extractError) && $text === '') {
        throw new RuntimeException($extractError);
    }
    throw new RuntimeException('Found text in the PDF but no clear transaction rows. Add a Gemini API key so the layout can be interpreted.');
}

function hydrate_extracted_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $date = parse_date((string) ($row['date'] ?? $row['txn_date'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $dir = strtolower((string) ($row['direction'] ?? 'out'));
        $direction = in_array($dir, ['in', 'credit', 'deposit'], true) ? 'in' : 'out';
        $amount = abs((float) str_replace(',', '', (string) ($row['amount'] ?? 0)));
        if (!$date || $description === '' || $amount < 0.01) {
            continue;
        }
        $receiver = trim((string) ($row['receiver'] ?? ''));
        if ($receiver === '') {
            $receiver = extract_receiver($description);
        }
        $description = $receiver !== '' && strcasecmp($receiver, 'Unknown') !== 0 ? $receiver : $description;
        [$category, $source] = categorize_transaction($description, $receiver, $direction);
        $out[] = [
            'txn_date' => $date,
            'description' => $description,
            'receiver' => $receiver,
            'amount' => $amount,
            'direction' => $direction,
            'category' => $category,
            'category_source' => $source,
            'fingerprint' => sha1($date . '|' . mb_strtolower($receiver) . '|' . number_format($amount, 2) . '|' . $direction . '|' . count($out)),
        ];
    }
    return $out;
}

function parse_bank_csv(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('Could not open the uploaded file.');
    }

    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if ($line === [null] || $line === false) {
            continue;
        }
        $rows[] = array_map(static fn($cell) => trim((string) $cell), $line);
    }
    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('The file looks empty.');
    }

    $start = 0;
    $header = [];
    foreach ($rows as $i => $row) {
        $joined = mb_strtolower(implode(' ', $row));
        if (looks_like_header($joined)) {
            $header = array_map('normalize_header', $row);
            $start = $i + 1;
            break;
        }
    }

    if (!$header) {
        $header = guess_positional_header($rows[0]);
        $start = count($rows[0]) > 2 ? 0 : 1;
        if ($start === 1) {
            $header = array_map('normalize_header', $rows[0]);
        }
    }

    $map = map_columns($header);
    $transactions = [];

    for ($i = $start; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (count(array_filter($row, static fn($v) => $v !== '')) < 2) {
            continue;
        }

        $parsed = row_to_transaction($row, $map);
        if ($parsed) {
            $transactions[] = $parsed;
        }
    }

    if (!$transactions) {
        throw new RuntimeException('No transactions could be read. Try a CSV with date, description, and debit/credit or amount columns.');
    }

    return $transactions;
}

function looks_like_header(string $joined): bool
{
    return (bool) preg_match('/date|description|particular|debit|credit|amount|withdrawal|deposit|narration/', $joined);
}

function normalize_header(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/', '_', $name);
    return trim($name, '_');
}

function guess_positional_header(array $row): array
{
    return ['date', 'description', 'amount', 'balance'];
}

function map_columns(array $header): array
{
    $map = ['date' => null, 'description' => null, 'debit' => null, 'credit' => null, 'amount' => null];

    foreach ($header as $i => $name) {
        if (preg_match('/^(txn_)?date$|transaction_date|posting_date|value_date/', $name) && $map['date'] === null) {
            $map['date'] = $i;
        } elseif (preg_match('/desc|particular|narration|details|merchant|receiver|beneficiary|payee/', $name) && $map['description'] === null) {
            $map['description'] = $i;
        } elseif (preg_match('/debit|withdrawal|money_out|cash_out/', $name)) {
            $map['debit'] = $i;
        } elseif (preg_match('/credit|deposit|money_in|cash_in/', $name)) {
            $map['credit'] = $i;
        } elseif (preg_match('/^amount$|txn_amount/', $name)) {
            $map['amount'] = $i;
        }
    }

    if ($map['date'] === null) {
        $map['date'] = 0;
    }
    if ($map['description'] === null) {
        $map['description'] = min(1, count($header) - 1);
    }

    return $map;
}

function parse_amount(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '-' || strcasecmp($raw, 'null') === 0) {
        return null;
    }

    $negative = false;
    if (preg_match('/^\(.*\)$/', $raw) || str_contains($raw, 'DR') || str_starts_with($raw, '-')) {
        $negative = true;
    }

    $raw = str_ireplace(['RM', 'MYR', 'DR', 'CR', ',', ' '], '', $raw);
    $raw = str_replace(['(', ')'], '', $raw);
    if (!is_numeric($raw)) {
        return null;
    }

    $value = (float) $raw;
    return $negative ? -abs($value) : $value;
}

function parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $formats = preg_match('/\d{4}/', $raw)
        ? ['Y-m-d', 'j/m/Y', 'd/m/Y', 'd-m-Y', 'j-m-Y', 'Y/m/d', 'd M Y', 'd-M-Y', 'Ymd']
        : ['j/m/y', 'd/m/y', 'j-m-y', 'd-m-y'];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $raw);
        $errors = DateTime::getLastErrors();
        if (!$dt instanceof DateTime || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            continue;
        }
        $year = (int) $dt->format('Y');
        if ($year < 100) {
            $year += 2000;
            $dt->setDate($year, (int) $dt->format('n'), (int) $dt->format('j'));
        }
        if ($year >= 1990 && $year <= 2100) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

function row_to_transaction(array $row, array $map): ?array
{
    $date = parse_date($row[$map['date']] ?? '');
    $description = $row[$map['description']] ?? '';
    if (!$date || $description === '') {
        return null;
    }

    $debit = $map['debit'] !== null ? parse_amount($row[$map['debit']] ?? '') : null;
    $credit = $map['credit'] !== null ? parse_amount($row[$map['credit']] ?? '') : null;
    $amount = $map['amount'] !== null ? parse_amount($row[$map['amount']] ?? '') : null;

    $direction = null;
    $value = 0.0;

    if ($debit !== null && abs($debit) > 0 && ($credit === null || abs($credit) < 0.0001)) {
        $direction = 'out';
        $value = abs($debit);
    } elseif ($credit !== null && abs($credit) > 0 && ($debit === null || abs($debit) < 0.0001)) {
        $direction = 'in';
        $value = abs($credit);
    } elseif ($amount !== null && abs($amount) > 0) {
        $direction = $amount < 0 ? 'out' : 'in';
        $value = abs($amount);
    } else {
        return null;
    }

    $receiver = extract_receiver($description);
    [$category, $source] = categorize_transaction($description, $receiver, $direction);
    $fingerprint = sha1($date . '|' . mb_strtolower($description) . '|' . number_format($value, 2) . '|' . $direction . '|' . bin2hex(random_bytes(4)));

    return [
        'txn_date' => $date,
        'description' => $description,
        'receiver' => $receiver,
        'amount' => $value,
        'direction' => $direction,
        'category' => $category,
        'category_source' => $source,
        'fingerprint' => $fingerprint,
    ];
}

function save_import(string $filename, array $transactions, ?float $opening = null, ?float $closing = null, ?float $withdrawalTotal = null, ?float $depositTotal = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $months = [];
        foreach ($transactions as $txn) {
            $month = substr((string) ($txn['txn_date'] ?? ''), 0, 7);
            if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                $months[$month] = true;
            }
        }
        $wipe = $pdo->prepare('DELETE FROM transactions WHERE strftime("%Y-%m", txn_date) = ?');
        foreach (array_keys($months) as $month) {
            $wipe->execute([$month]);
        }

        $month = array_key_first($months) ?: '';
        $insImport = $pdo->prepare('INSERT INTO imports (filename, imported_at, row_count, added_count, statement_month, opening_balance, closing_balance, total_withdrawal, total_deposit) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)');
        $insImport->execute([$filename, date('c'), count($transactions), $month, $opening, $closing, $withdrawalTotal, $depositTotal]);
        $importId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('
            INSERT OR IGNORE INTO transactions
            (import_id, txn_date, description, receiver, amount, direction, category, category_source, fingerprint, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "", ?)
        ');

        $added = 0;
        foreach ($transactions as $txn) {
            $ins->execute([
                $importId,
                $txn['txn_date'],
                $txn['description'],
                $txn['receiver'],
                $txn['amount'],
                $txn['direction'],
                $txn['category'],
                $txn['category_source'],
                $txn['fingerprint'],
                date('c'),
            ]);
            if ($ins->rowCount() > 0) {
                $added++;
            }
        }

        $pdo->prepare('UPDATE imports SET added_count = ? WHERE id = ?')->execute([$added, $importId]);
        $pdo->commit();

        return [
            'import_id' => $importId,
            'row_count' => count($transactions),
            'added' => $added,
            'skipped' => count($transactions) - $added,
            'months' => array_keys($months),
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
