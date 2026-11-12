<?php

function extract_pdf_text(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false || !str_starts_with($raw, '%PDF')) {
        throw new RuntimeException('That file is not a readable PDF.');
    }
    if (str_contains($raw, '/Encrypt')) {
        throw new RuntimeException('This statement is password-protected. Open it, print/save as a new PDF without a password, then upload again.');
    }

    $chunks = [];
    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $matches)) {
        foreach ($matches[1] as $stream) {
            $decoded = decode_pdf_stream($stream);
            if ($decoded === '' || substr_count($decoded, "\0") > strlen($decoded) * 0.05) {
                continue;
            }
            if (!preg_match('/Tj|TJ|Td|Tm|\(/', $decoded)) {
                continue;
            }
            $text = pdf_operators_to_text($decoded);
            if (mb_strlen(trim($text)) > 8) {
                $chunks[] = $text;
            }
        }
    }

    return trim(implode("\n\n", $chunks));
}

function decode_pdf_stream(string $stream): string
{
    $stream = preg_replace("/^\r?\n/", '', $stream) ?? $stream;
    $stream = preg_replace("/\r?\n$/", '', $stream) ?? $stream;
    $tries = [
        @gzuncompress($stream),
        @gzinflate($stream),
        @gzinflate(substr($stream, 2)),
    ];
    foreach ($tries as $try) {
        if (is_string($try) && $try !== '') {
            return $try;
        }
    }
    return $stream;
}

function pdf_operators_to_text(string $content): string
{
    $len = strlen($content);
    $x = 0.0;
    $y = 0.0;
    $leading = 12.0;
    $lastY = null;
    $lastX = 0.0;
    $out = '';
    $i = 0;

    $emit = static function (string $text) use (&$out, &$x, &$y, &$lastY, &$lastX): void {
        if ($text === '') {
            return;
        }
        if ($lastY !== null && abs($y - $lastY) > 3) {
            $out .= "\n";
        } elseif ($x > $lastX + 4) {
            $out .= ' ';
        }
        $out .= $text;
        $lastY = $y;
        $lastX = $x + strlen($text) * 3.2;
    };

    while ($i < $len) {
        $ch = $content[$i];
        if ($ch === '(') {
            [$str, $i] = read_pdf_literal($content, $i);
            $rest = substr($content, $i + 1, 20);
            if (preg_match('/^\s*\'/', $rest)) {
                $y -= $leading;
                $emit($str);
                $i += (int) strpos($rest, "'") + 1;
                continue;
            }
            $emit($str);
            continue;
        }
        if ($ch === '[' && preg_match('/^\[(.*?)\]\s*TJ/s', substr($content, $i), $m)) {
            $emit(pdf_tj_text($m[1]));
            $i += strlen($m[0]) - 1;
            continue;
        }

        if (preg_match('/^(-?[\d.]+)\s+(-?[\d.]+)\s+Td/', substr($content, $i, 40), $m)) {
            $x += (float) $m[1];
            $y += (float) $m[2];
            $i += strlen($m[0]) - 1;
            continue;
        }
        if (preg_match('/^(-?[\d.]+)\s+(-?[\d.]+)\s+TD/', substr($content, $i, 40), $m)) {
            $leading = -(float) $m[2];
            $x += (float) $m[1];
            $y += (float) $m[2];
            $i += strlen($m[0]) - 1;
            continue;
        }
        if (preg_match('/^(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Tm/', substr($content, $i, 120), $m)) {
            $x = (float) $m[5];
            $y = (float) $m[6];
            $i += strlen($m[0]) - 1;
            continue;
        }
        if (preg_match('/^(-?[\d.]+)\s+TL/', substr($content, $i, 24), $m)) {
            $leading = (float) $m[1];
            $i += strlen($m[0]) - 1;
            continue;
        }
        if ($ch === 'T' && ($content[$i + 1] ?? '') === '*') {
            $y -= $leading;
            $i++;
            continue;
        }
        $i++;
    }

    $out = preg_replace('/[^\P{C}\n]+/u', ' ', $out) ?? $out;
    $out = preg_replace("/[ \t]+/", ' ', $out) ?? $out;
    $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
    return trim($out);
}

function pdf_tj_text(string $arrayBody): string
{
    $out = '';
    $len = strlen($arrayBody);
    for ($i = 0; $i < $len; $i++) {
        if ($arrayBody[$i] === '(') {
            [$str, $i] = read_pdf_literal($arrayBody, $i);
            $out .= $str;
        } elseif ($arrayBody[$i] === '<' && ($arrayBody[$i + 1] ?? '') !== '<') {
            [$str, $i] = read_pdf_hex($arrayBody, $i);
            $out .= $str;
        }
    }
    return $out;
}

function read_pdf_literal(string $content, int $start): array
{
    $out = '';
    $len = strlen($content);
    for ($i = $start + 1; $i < $len; $i++) {
        $ch = $content[$i];
        if ($ch === '\\' && $i + 1 < $len) {
            $next = $content[$i + 1];
            $map = ['n' => "\n", 'r' => "\r", 't' => "\t", '(' => '(', ')' => ')', '\\' => '\\'];
            $out .= $map[$next] ?? $next;
            $i++;
            continue;
        }
        if ($ch === ')') {
            return [$out, $i];
        }
        $out .= $ch;
    }
    return [$out, $len - 1];
}

function read_pdf_hex(string $content, int $start): array
{
    $end = strpos($content, '>', $start);
    if ($end === false) {
        return ['', $start];
    }
    $hex = preg_replace('/\s+/', '', substr($content, $start + 1, $end - $start - 1)) ?? '';
    if (strlen($hex) % 2 === 1) {
        $hex .= '0';
    }
    $bin = @hex2bin($hex);
    return [is_string($bin) ? $bin : '', $end];
}

function parse_statement_text(string $text): array
{
    if (preg_match('/tempoh penyata|transaksi akaun|pengeluaran|qard sa/i', $text)) {
        $islam = parse_bank_islam_text($text);
        if ($islam['rows']) {
            return $islam;
        }
    }

    $periodStart = null;
    $periodEnd = null;
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\s*[-–]\s*(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $p)) {
        $periodStart = parse_date($p[1]);
        $periodEnd = parse_date($p[2]);
    } elseif (preg_match('/(statement\s*period|period|for the month of|as at)[:\s]+(.{6,60})/i', $text, $m)) {
        if (preg_match_all('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{2,4}|[A-Za-z]{3,9}\s+\d{4})/', $m[2], $dates)) {
            $periodStart = parse_date($dates[1][0] ?? '') ?: null;
            $periodEnd = parse_date($dates[1][1] ?? '') ?: $periodStart;
        }
    }

    $rows = [];
    $yearHint = $periodStart ? substr($periodStart, 0, 4) : date('Y');
    $lines = preg_split('/\R/', $text) ?: [];

    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        if ($line === '' || looks_like_header(mb_strtolower($line))) {
            continue;
        }
        if (preg_match('/opening balance|closing balance|total debit|total credit|page \d/i', $line)) {
            continue;
        }

        if (!preg_match('/^(?<date>\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|\d{1,2}\s+[A-Za-z]{3,9}(?:\s+\d{2,4})?)\s+(?<rest>.+)$/', $line, $m)) {
            continue;
        }

        $dateRaw = $m['date'];
        if (!preg_match('/\d{4}/', $dateRaw)) {
            $dateRaw .= '/' . $yearHint;
        }
        $date = parse_date($dateRaw);
        if (!$date) {
            continue;
        }

        $rest = $m['rest'];
        $direction = null;
        $amount = null;
        $description = '';

        if (preg_match('/(?<amt>[\d,]+\.\d{2})\s*(?<side>DR|CR|debit|credit)\b/i', $rest, $am)) {
            $amount = parse_amount($am['amt']);
            $side = strtoupper($am['side']);
            $direction = in_array($side, ['CR', 'CREDIT'], true) ? 'in' : 'out';
            $description = trim(str_replace($am[0], '', $rest));
        } elseif (preg_match_all('/[\d,]+\.\d{2}/', $rest, $amts)) {
            $nums = $amts[0];
            $description = trim(preg_replace('/[\d,]+\.\d{2}/', '', $rest) ?? '');
            if (count($nums) >= 2) {
                $first = parse_amount($nums[0]);
                $second = parse_amount($nums[1]);
                if ($first && !$second) {
                    $direction = 'out';
                    $amount = $first;
                } elseif ($second && !$first) {
                    $direction = 'in';
                    $amount = $second;
                } else {
                    $direction = 'out';
                    $amount = $first;
                }
            } else {
                $amount = parse_amount($nums[0]);
                $direction = 'out';
            }
        } else {
            continue;
        }

        $description = trim($description);
        if (!$date || !$amount || $description === '' || mb_strlen($description) < 3) {
            continue;
        }

        $rows[] = compact('date', 'description', 'amount', 'direction');
    }

    return [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'rows' => $rows,
        'opening_balance' => null,
        'closing_balance' => null,
        'total_withdrawal' => null,
        'total_deposit' => null,
    ];
}

function parse_bank_islam_text(string $text): array
{
    $periodStart = null;
    $periodEnd = null;
    if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})\s*[-–]\s*(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $p)) {
        $periodStart = parse_date($p[1]);
        $periodEnd = parse_date($p[2]);
    }

    $pages = preg_split('/(?:PAGE\s+\d+|--\s*\d+\s+of\s+\d+\s*--)/i', $text) ?: [$text];
    $rows = [];
    $opening = null;
    $closing = null;
    $totalWithdrawal = null;
    $totalDeposit = null;
    foreach ($pages as $page) {
        $pack = islam_collect_page($page);
        if (!$pack) {
            continue;
        }
        if ($pack['has_bf'] && $pack['rest']) {
            $opening = $opening ?? islam_num($pack['rest'][0]);
        }
        if (isset($pack['total_withdrawal']) && $pack['total_withdrawal'] !== null) {
            $totalWithdrawal = (float) $pack['total_withdrawal'];
        }
        if (isset($pack['total_deposit']) && $pack['total_deposit'] !== null) {
            $totalDeposit = (float) $pack['total_deposit'];
        }
        if (!empty($pack['rest'])) {
            for ($k = count($pack['rest']) - 1; $k >= 0; $k--) {
                $end = islam_num($pack['rest'][$k]);
                if ($end >= 0.005) {
                    $closing = $end;
                    break;
                }
            }
        }
        $rows = array_merge($rows, islam_rows_from_pack($pack));
    }

    return [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'rows' => $rows,
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'total_withdrawal' => $totalWithdrawal,
        'total_deposit' => $totalDeposit,
    ];
}

function parse_bank_islam_page(string $page, ?string $periodStart): array
{
    $pack = islam_collect_page($page);
    if (!$pack) {
        return [];
    }
    return islam_rows_from_pack($pack);
}

function islam_is_amount_line(string $line): bool
{
    return (bool) preg_match('/^[\d,]*\.\d{2}-?$/', trim($line));
}

function islam_num(string $raw): float
{
    $raw = str_replace([',', ' '], '', trim($raw));
    $raw = rtrim($raw, '-');
    return abs((float) $raw);
}

function islam_collect_page(string $page): ?array
{
    $lines = [];
    foreach (preg_split('/\R/', $page) ?: [] as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        $line = str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    $dates = [];
    $descs = [];
    $amounts = [];
    $details = [];
    $phase = 'head';

    foreach ($lines as $line) {
        if (preg_match('/^\d{1,2}\/\d{2}\/\d{2}$/', $line)) {
            $dates[] = $line;
            $phase = 'dates';
            continue;
        }
        if ($phase === 'head') {
            continue;
        }
        if (islam_txn_type($line) && $phase !== 'money' && $phase !== 'tail') {
            $descs[] = strtoupper($line);
            $phase = 'descs';
            continue;
        }
        if (islam_is_amount_line($line)) {
            $amounts[] = $line;
            $phase = 'money';
            continue;
        }
        if (islam_boilerplate($line) || islam_ref($line)) {
            continue;
        }
        if ($phase === 'money' || $phase === 'tail') {
            $phase = 'tail';
            if (mb_strlen($line) >= 3 && !islam_is_amount_line($line)) {
                $details[] = $line;
            }
        }
    }

    if (count($descs) < 1 || count($amounts) < 2) {
        return null;
    }

    $hasBf = strtoupper($descs[0] ?? '') === 'B/F';
    $types = $hasBf ? array_slice($descs, 1) : $descs;
    if (count($dates) === count($types) + 1 && $hasBf) {
        $dates = array_slice($dates, 1);
    }
    $n = min(count($dates), count($types));
    if ($n === 0 || count($amounts) < $n * 2) {
        return null;
    }

    $hasTotal = false;
    foreach ($lines as $line) {
        if (strcasecmp($line, 'TOTAL') === 0) {
            $hasTotal = true;
            break;
        }
    }

    $withdrawals = array_slice($amounts, 0, $n);
    $totalWithdrawal = null;
    $totalDeposit = null;
    if ($hasTotal && count($amounts) >= ($n * 2) + 2) {
        $totalWithdrawal = islam_num($amounts[$n]);
        $deposits = array_slice($amounts, $n + 1, $n);
        $totalDeposit = islam_num($amounts[($n * 2) + 1]);
        $rest = array_slice($amounts, ($n * 2) + 2);
    } else {
        $deposits = array_slice($amounts, $n, $n);
        $rest = array_slice($amounts, $n * 2);
    }

    return [
        'has_bf' => $hasBf,
        'n' => $n,
        'dates' => array_slice($dates, 0, $n),
        'types' => array_slice($types, 0, $n),
        'withdrawals' => $withdrawals,
        'deposits' => $deposits,
        'rest' => $rest,
        'total_withdrawal' => $totalWithdrawal,
        'total_deposit' => $totalDeposit,
        'groups' => islam_detail_groups($details),
    ];
}

function islam_rows_from_pack(array $pack): array
{
    $n = (int) $pack['n'];
    $withdrawals = $pack['withdrawals'];
    $deposits = $pack['deposits'];
    islam_unshift_stray_deposits($withdrawals, $deposits);
    $groups = $pack['groups'];
    $totW = isset($pack['total_withdrawal']) && $pack['total_withdrawal'] !== null ? (float) $pack['total_withdrawal'] : null;
    $totD = isset($pack['total_deposit']) && $pack['total_deposit'] !== null ? (float) $pack['total_deposit'] : null;
    $gi = 0;
    $rows = [];

    for ($i = 0; $i < $n; $i++) {
        $date = parse_date($pack['dates'][$i] ?? '');
        $outAmt = islam_num($withdrawals[$i] ?? '0');
        $inAmt = islam_num($deposits[$i] ?? '0');
        $type = $pack['types'][$i] ?? '';
        if ($totW !== null && abs($outAmt - $totW) < 0.04 && $inAmt >= 0.01) {
            $outAmt = 0.0;
        }
        if ($totD !== null && abs($inAmt - $totD) < 0.04 && $outAmt >= 0.01) {
            $inAmt = 0.0;
        }
        if ($totW !== null && abs($inAmt - $totW) < 0.04) {
            $inAmt = 0.0;
        }
        if ($totD !== null && abs($outAmt - $totD) < 0.04) {
            $outAmt = 0.0;
        }
        $picked = islam_column_amount($type, $outAmt, $inAmt);
        if ($picked === null || !$date) {
            continue;
        }
        [$direction, $amount] = $picked;
        if ($totW !== null && abs($amount - $totW) < 0.04) {
            continue;
        }
        if ($totD !== null && abs($amount - $totD) < 0.04) {
            continue;
        }
        $receiver = islam_pick_receiver($groups, $gi, $type, $direction, $amount);
        if (preg_match('/ENDING BALANCE|BEGINNING BALANCE|\bTOTAL\b/i', $receiver) || islam_is_amount_line($receiver)) {
            $receiver = 'Unknown';
        }
        $description = $receiver;
        $rows[] = compact('date', 'description', 'receiver', 'amount', 'direction');
    }

    return $rows;
}

function islam_acquirer_line(string $line): bool
{
    return (bool) preg_match('/^(MBB\b|MAYBANK|PUBLIC BANK BERHAD|RHB BANK BERHAD|CIMB |HONG LEONG|HLBB)/i', $line);
}

function islam_detail_label(string $line): bool
{
    return (bool) preg_match('/^(weekly allowance|seb allowance|fund transfer|exam fee|exam refund|math camp|extra|subscription|mio\s?math|rt reimbursement|application cambridge|tara,?\s*tmua)$/i', $line);
}

function islam_person_line(string $line): bool
{
    if (preg_match('/GRAB|MART|BANK|QR Payment|BERHAD|SDN|BHD|STORE|FOOD|RAIL|HOSTINGER|PETALIN|SELANGO/i', $line)) {
        return false;
    }
    return (bool) preg_match('/^(VENNY SOETANTO|LOVENIA LAW CIA SHIN|[A-Z][A-Z\'.]+(?: [A-Z][A-Z\'.]+){1,4})$/i', $line);
}

function islam_is_family_detail(string $group): bool
{
    return (bool) preg_match('/weekly allowance|seb allowance/i', $group);
}

function islam_pick_receiver(array &$groups, int &$gi, string $type, string $direction, float $amount): string
{
    $familyTxn = (bool) preg_match('/TRANSFER|SERVICE CHARGE/i', $type);
    $wantMum = $familyTxn && $direction === 'in' && $amount >= 180 && $amount <= 270;
    $n = count($groups);
    for ($j = $gi; $j < $n; $j++) {
        $group = $groups[$j];
        if (islam_is_amount_line($group)) {
            continue;
        }
        $family = islam_is_family_detail($group);
        if ($family && !$familyTxn) {
            continue;
        }
        if (!$family && $wantMum) {
            continue;
        }
        array_splice($groups, $j, 1);
        return $group !== '' ? $group : 'Unknown';
    }
    $receiver = $groups[$gi] ?? 'Unknown';
    if (isset($groups[$gi])) {
        array_splice($groups, $gi, 1);
    }
    return $receiver !== '' ? $receiver : 'Unknown';
}

function islam_unshift_stray_deposits(array &$withdrawals, array &$deposits): void
{
    $n = count($withdrawals);
    for ($i = 1; $i < $n; $i++) {
        $prevOut = abs((float) str_replace(',', '', $withdrawals[$i - 1] ?? '0'));
        $prevIn = abs((float) str_replace(',', '', $deposits[$i - 1] ?? '0'));
        $outAmt = abs((float) str_replace(',', '', $withdrawals[$i] ?? '0'));
        $inAmt = abs((float) str_replace(',', '', $deposits[$i] ?? '0'));
        if ($prevOut < 0.01 && $prevIn < 0.01 && $outAmt >= 0.01 && $inAmt >= 0.01) {
            $deposits[$i - 1] = $deposits[$i];
            $deposits[$i] = '0.00';
        }
    }
}

function islam_column_amount(string $type, float $outAmt, float $inAmt): ?array
{
    $out = $outAmt >= 0.01;
    $in = $inAmt >= 0.01;
    if ($out && !$in) {
        return ['out', $outAmt];
    }
    if ($in && !$out) {
        return ['in', $inAmt];
    }
    if (!$out && !$in) {
        return null;
    }
    $debit = (bool) preg_match('/DEBIT|PURCHASE|WITHDRAWAL|CHARGE|\bQR\b|FPX DEBIT/i', $type);
    $credit = (bool) preg_match('/CREDIT|CR TFR|HIBAH|INWARD|SAL\/MISC|TRANSFER/i', $type);
    if ($debit && !$credit) {
        return ['out', $outAmt];
    }
    if ($credit && !$debit) {
        return ['in', $inAmt];
    }
    return $outAmt >= $inAmt ? ['out', $outAmt] : ['in', $inAmt];
}

function islam_is_footer_line(string $line): bool
{
    return (bool) preg_match('/^(NO |LOT |JALAN |JLN |KUCHING|MYR|OLcustom)/i', $line)
        || (bool) preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}\s*[-–]/', $line);
}

function islam_detail_groups(array $details): array
{
    $groups = [];
    $i = 0;
    $n = count($details);
    while ($i < $n) {
        $line = $details[$i];
        if (islam_is_amount_line($line)) {
            $i++;
            continue;
        }
        if (islam_is_footer_line($line)) {
            break;
        }
        if (isset($details[$i + 1]) && islam_is_footer_line($details[$i + 1]) && preg_match('/LOVENIA/i', $line)) {
            break;
        }
        if (preg_match('/^QR Payment$/i', $line)) {
            $skip = 0;
            while ($i < $n && preg_match('/^QR Payment$/i', $details[$i]) && $skip < 2) {
                $i++;
                $skip++;
            }
            while ($i < $n && (islam_is_amount_line($details[$i]) || islam_ref($details[$i]))) {
                $i++;
            }
            if ($i < $n && !islam_is_footer_line($details[$i]) && !preg_match('/^(QR Payment|Fund Transfer)$/i', $details[$i]) && !islam_is_amount_line($details[$i])) {
                $groups[] = $details[$i];
                $i++;
            } else {
                $groups[] = 'Unknown';
            }
            continue;
        }
        if (preg_match('/^(Fund Transfer|Outward ACH|Inward ACH)$/i', $line)) {
            $i++;
            while ($i < $n && (islam_is_amount_line($details[$i]) || islam_ref($details[$i]))) {
                $i++;
            }
            if ($i < $n && !islam_is_footer_line($details[$i]) && !preg_match('/^(QR Payment|Fund Transfer)$/i', $details[$i]) && !islam_is_amount_line($details[$i])) {
                $groups[] = $details[$i];
                $i++;
            } else {
                $groups[] = 'Unknown';
            }
            continue;
        }
        if (islam_acquirer_line($line)) {
            $i++;
            while ($i < $n && (islam_is_amount_line($details[$i]) || islam_ref($details[$i]))) {
                $i++;
            }
            if ($i < $n && !islam_is_footer_line($details[$i]) && !islam_acquirer_line($details[$i]) && !preg_match('/^(QR Payment|Fund Transfer|weekly allowance|seb allowance)$/i', $details[$i]) && !islam_is_amount_line($details[$i])) {
                $groups[] = $details[$i];
                $i++;
            } else {
                $groups[] = $line;
            }
            continue;
        }
        if (islam_detail_label($line) && isset($details[$i + 1]) && islam_person_line($details[$i + 1])) {
            $groups[] = trim($line . ' ' . $details[$i + 1]);
            $i += 2;
            continue;
        }
        if (preg_match('/^Subscription /i', $line)) {
            $i++;
            continue;
        }
        $groups[] = $line;
        $i++;
    }
    return $groups;
}

function islam_txn_type(string $line): bool
{
    if (islam_ref($line) || preg_match('/^(TOTAL|ENDING BALANCE|BEGINNING BALANCE)$/i', $line)) {
        return false;
    }
    if (preg_match('/^(B\/F|DRC DEBIT|DRC CREDIT|DUITNOW TRANSFER|DUITNOW QR|MYDEBIT PURCHASE(?:\s+-\s+S)?|CASH WITHDRAWAL|ATM WITHDRAWAL|IBG TRANSFER|FUND TRANSFER|CR TFR\/SAL\/MISC|SERVICE CHARGE\/MISC|INWARD IBG|INWARD CREDIT|INWARD FUND|HIBAH PAID|FPX DEBIT|FPX CREDIT|CASH DEPOSIT|CHEQUE DEPOSIT|CARDLESS WITHDRAWAL|GIRO|INSTANT TRANSFER)$/i', $line)) {
        return true;
    }
    return (bool) preg_match('/^[A-Z][A-Z0-9&\/ ]{1,28}$/', $line)
        && !preg_match('/\d{3,}/', $line)
        && mb_strlen($line) <= 24
        && !preg_match('/BERHAD|SDN|BHD|STORE|MART|GRAB|FOOD|BANK|ENERGY|ALLOWANCE|LOVENIA|VENNY|CHICKEN|RAMEN|HOSTINGER|AGODA/i', $line);
}

function islam_ref(string $line): bool
{
    return (bool) preg_match('/^(?=.*\d)[A-Z0-9]{8,}$/', $line);
}

function islam_boilerplate(string $line): bool
{
    return (bool) preg_match('/' . implode('|', [
        'ground floor',
        'al idrus',
        'nombor bebas',
        'toll free',
        'tempoh penyata',
        'statement period',
        'no\.?\s*akaun',
        'account no',
        'cawangan',
        'produk',
        'qard',
        'matawang',
        'currency',
        'dilindungi',
        'protected by pidm',
        'penyata akaun',
        'account statement',
        'transaksi akaun',
        'account transaction',
        'tarikh',
        '^date$',
        'perkara',
        'description',
        'no\.cek',
        'cheque',
        'pengeluaran',
        'withdrawal',
        'simpanan',
        '^deposit$',
        'baki',
        '^balance$',
        'butir-butir',
        'additional details',
        'segala bilangan',
        'all items and balances',
        'tanda "-"',
        'debit balance',
        'sila maklumkan',
        'change of address',
        'sebarang cek',
        'cheques deposited',
        'kuching, sarawak',
        'jalan satok',
        'jalan green',
        '^total$',
        'ending balance',
        'beginning balance',
    ]) . '/i', $line);
}
