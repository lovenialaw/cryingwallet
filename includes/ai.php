<?php

function gemini_key_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'gemini.key';
}

function gemini_api_key(): string
{
    $env = getenv('GEMINI_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $file = gemini_key_path();
    if (is_file($file)) {
        $fromFile = trim((string) file_get_contents($file));
        if ($fromFile !== '') {
            return $fromFile;
        }
    }

    return trim(setting('gemini_api_key'));
}

function save_gemini_api_key(string $key): void
{
    $key = trim($key);
    set_setting('gemini_api_key', $key);

    $dir = dirname(gemini_key_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if ($key === '') {
        if (is_file(gemini_key_path())) {
            unlink(gemini_key_path());
        }
        return;
    }
    file_put_contents(gemini_key_path(), $key);
}

function gemini_extract_statement(string $pdfPath, string $filename): array
{
    $key = gemini_api_key();
    if ($key === '') {
        throw new RuntimeException('No Gemini API key saved.');
    }

    $bytes = file_get_contents($pdfPath);
    if ($bytes === false) {
        throw new RuntimeException('Could not read the PDF.');
    }
    if (strlen($bytes) > 12 * 1024 * 1024) {
        throw new RuntimeException('PDF is larger than 12MB. Split the statement or export a shorter month.');
    }

    $prompt = gemini_statement_prompt($filename);
    $model = setting('gemini_model', 'gemini-3.5-flash') ?: 'gemini-3.5-flash';
    $lastError = 'Gemini did not return transactions.';

    $tries = array_values(array_unique([$model, 'gemini-3.5-flash']));

    foreach ($tries as $name) {
        try {
            $json = gemini_post($name, $key, [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inlineData' => [
                                'mimeType' => 'application/pdf',
                                'data' => base64_encode($bytes),
                            ],
                        ],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                ],
            ]);
            $parsed = json_decode($json, true);
            if (!is_array($parsed) || empty($parsed['transactions']) || !is_array($parsed['transactions'])) {
                throw new RuntimeException('Gemini returned no transactions.');
            }
            set_setting('gemini_model', $name);
            $parsed['engine'] = 'gemini:' . $name;
            return $parsed;
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            if (preg_match('/use models\/([a-z0-9.\-]+)/i', $lastError, $hint) && !in_array($hint[1], $tries, true)) {
                $tries[] = $hint[1];
            }
            if (stripos($lastError, 'timed out') !== false) {
                throw new RuntimeException('Reading the PDF took too long. Try a shorter statement (one month) and upload again.');
            }
        }
    }

    throw new RuntimeException($lastError);
}

function classify_receivers_with_ai(array $txns): array
{
    if (!$txns) {
        return $txns;
    }

    $need = [];
    foreach ($txns as $i => $txn) {
        $receiver = trim((string) ($txn['receiver'] ?? ''));
        if ($receiver === '' || strcasecmp($receiver, 'Unknown') === 0) {
            continue;
        }
        $cached = cached_payee_label($receiver);
        if ($cached !== '') {
            $txns[$i]['category'] = $cached;
            $txns[$i]['category_source'] = 'user';
            continue;
        }
        [$keywordCat] = categorize_transaction($txn['description'] ?? $receiver, $receiver, $txn['direction'] ?? 'out');
        if (in_array($keywordCat, ['Scholarship', 'Family transfer'], true)) {
            $txns[$i]['category'] = $keywordCat;
            $txns[$i]['category_source'] = 'auto';
            continue;
        }
        $need[$receiver] = true;
    }

    $names = array_keys($need);
    if (!$names || gemini_api_key() === '') {
        return $txns;
    }

    try {
        $labels = gemini_classify_payees($names);
    } catch (Throwable $e) {
        return $txns;
    }

    $valid = db()->query('SELECT name FROM categories')->fetchAll(PDO::FETCH_COLUMN);
    $valid = array_flip($valid);

    foreach ($txns as $i => $txn) {
        $receiver = trim((string) ($txn['receiver'] ?? ''));
        if ($receiver === '' || !isset($labels[$receiver])) {
            continue;
        }
        $category = $labels[$receiver];
        if (!isset($valid[$category])) {
            $category = 'Unknown';
        }
        $txns[$i]['category'] = $category;
        $txns[$i]['category_source'] = 'auto';
        remember_payee_label($receiver, $category);
    }

    return $txns;
}

function cached_payee_label(string $payee): string
{
    $stmt = db()->prepare('SELECT category FROM payee_labels WHERE payee = ?');
    $stmt->execute([mb_strtolower(trim($payee))]);
    $row = $stmt->fetch();
    return $row ? (string) $row['category'] : '';
}

function remember_payee_label(string $payee, string $category): void
{
    db()->prepare('INSERT INTO payee_labels (payee, category, updated_at) VALUES (?, ?, ?)
        ON CONFLICT(payee) DO UPDATE SET category = excluded.category, updated_at = excluded.updated_at')
        ->execute([mb_strtolower(trim($payee)), $category, date('c')]);
}

function gemini_classify_payees(array $names): array
{
    $key = gemini_api_key();
    $model = setting('gemini_model', 'gemini-3.5-flash') ?: 'gemini-3.5-flash';
    $list = json_encode(array_values($names), JSON_UNESCAPED_UNICODE);
    $prompt = <<<PROMPT
These are payee / merchant names from the Additional Details column of a Malaysian bank statement.
They are the people or shops that received the money — NOT the payment method (ignore DuitNow, MyDebit, TnG, DRC).

Guess the spending type from the name alone. Use exactly one of:
Food, Transport, Exam, Online shopping, Family transfer, Scholarship, Bills, Cash, Transfer, Unknown

Guidance:
- Grab, GrabPay, Grab rides, Rapid Rail, Maxim, petrol, parking → Transport
- Bakeries, restaurants, chicken rice, Tealive, cafes, food marts, 7-Eleven snacks → Food
- Universities, MUET, exam, tuition, school fees → Exam
- Shopee, Lazada, TikTok, shopping malls, marketplaces, PDF tools → Online shopping
- ChatGPT, OpenAI, Hostinger, Cursor, Unifi, TNB, phone bills, software subs → Bills
- "Weekly allowance", mum/mom, or family nicknames — especially money IN — → Family transfer (never Scholarship)
- Sarawak Energy / SR Energy Berhad / SEB / sponsor body credits (money IN) → Scholarship
- Do not call weekly pocket money "Scholarship" just because the person is a scholar
- A person's full name with no shop/brand signal → Transfer
- Empty, "QR Payment" only, random refs → Unknown

Return JSON: {"labels":[{"name":"EXACT NAME FROM THE LIST","category":"Food"}]}
Include every name from this list:
{$list}
PROMPT;

    $json = gemini_post($model, $key, [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
        ],
    ]);
    $parsed = json_decode($json, true);
    $out = [];
    foreach ($parsed['labels'] ?? [] as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $category = trim((string) ($row['category'] ?? ''));
        if ($name !== '' && $category !== '') {
            $out[$name] = $category;
        }
    }
    return $out;
}

function relabel_month_receivers(string $month): int
{
    $stmt = db()->prepare('SELECT * FROM transactions WHERE strftime("%Y-%m", txn_date) = ? AND category_source != "user"');
    $stmt->execute([$month]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    foreach ($rows as $i => $row) {
        $receiver = trim((string) $row['receiver']);
        $stripped = preg_replace('/^(DRC DEBIT|DRC CREDIT|DUITNOW TRANSFER|DUITNOW QR|MYDEBIT PURCHASE(?:\s+-\s+S)?)\s*/i', '', (string) $row['description']);
        $stripped = trim((string) $stripped);
        if ($receiver === '' || preg_match('/^(DRC |DUITNOW|MYDEBIT)/i', $receiver)) {
            $receiver = $stripped !== '' ? $stripped : $receiver;
        }
        if (preg_match('/^(QR Payment|Fund Transfer)$/i', $receiver)) {
            $receiver = 'Unknown';
        }
        $rows[$i]['receiver'] = $receiver !== '' ? $receiver : 'Unknown';
        $rows[$i]['description'] = $rows[$i]['receiver'];
    }

    $rows = classify_receivers_with_ai($rows);
    $upd = db()->prepare('UPDATE transactions SET receiver = ?, description = ?, category = ?, category_source = ? WHERE id = ?');
    $n = 0;
    foreach ($rows as $row) {
        $upd->execute([
            $row['receiver'],
            $row['description'],
            $row['category'],
            $row['category_source'] ?? 'auto',
            $row['id'],
        ]);
        $n++;
    }
    return $n;
}

function gemini_statement_prompt(string $filename): string
{
    return <<<PROMPT
Extract every bank account transaction from this Malaysian bank statement named "{$filename}".

Return JSON only, with this shape:
{
  "period_start": "YYYY-MM-DD or null",
  "period_end": "YYYY-MM-DD or null",
  "transactions": [
    {
      "date": "YYYY-MM-DD",
      "description": "full original description including receiver / merchant name",
      "amount": 12.34,
      "direction": "in"
    }
  ]
}

Rules:
- direction is "in" for credit / deposit / money in, "out" for debit / withdrawal / money out.
- amount is always a positive number in MYR, no commas or RM.
- Skip opening balance, closing balance, totals, ads, and headers.
- Keep the original receiver/merchant wording so we can categorise it.
- If a description wraps across lines, join it into one transaction.
- Include the statement month/period if printed.
PROMPT;
}

function gemini_post(string $model, string $key, array $payload): string
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Could not reach Gemini: ' . $err);
    }

    $data = json_decode($body, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('Gemini HTTP ' . $code);
        if ($code === 503) {
            $msg = 'Gemini is busy. Wait a few seconds and upload again.';
        }
        throw new RuntimeException($msg);
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('Gemini sent an empty reply.');
    }
    return trim($text);
}
