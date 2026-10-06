<?php
/**
 * Eval360 logframe import — server side.
 *
 * One job: take a logframe document from the browser, send it to OpenAI with a
 * strict JSON schema, and return the structured framework. The API key lives
 * in .env on the server and never reaches the browser, a log, or an error.
 *
 * No framework, no Composer: curl + json only (PHP 8.2).
 */
declare(strict_types=1);

if (!defined('LF_ROOT')) {
    define('LF_ROOT', dirname(__DIR__));
}

/* ── .env ──────────────────────────────────────────────────────────────────
   Looked up in this order:
     1. the path in the LOGFRAME_ENV_FILE server variable;
     2. <domain>/private/logframe.env — three levels above this project, i.e.
        outside public_html, so the web server can never serve it (preferred);
     3. .env in the project root — blocked by .htaccess, but only if the
        server honours .htaccess. */
function lf_env_load(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $candidates = array_filter([getenv('LOGFRAME_ENV_FILE') ?: null, dirname(LF_ROOT, 3) . '/private/logframe.env', LF_ROOT . '/.env']);
    foreach ($candidates as $file) {
        if (!is_readable($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) $v = substr($v, 1, -1);
            $_ENV[$k] = $v;
        }
        return;
    }
}
function lf_env(string $key, ?string $default = null): ?string
{
    lf_env_load();
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : (string) $v;
}
function lf_int(string $key, int $default): int
{
    $v = lf_env($key);
    return is_numeric($v) ? (int) $v : $default;
}

/* ── Configuration (all overridable in .env) ───────────────────────────── */
function lf_config(): array
{
    return [
        'key_set'       => lf_env('OPENAI_API_KEY') !== null,
        'mock'          => lf_env('AI_MOCK') === '1',
        'model'         => lf_env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'endpoint'      => lf_env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/responses'),
        'per_ip_day'    => lf_int('AI_LIMIT_PER_IP_DAY', 10),
        'total_day'     => lf_int('AI_LIMIT_TOTAL_DAY', 300),
        'max_chars'     => lf_int('AI_MAX_TEXT_CHARS', 150000),
        'max_pdf_bytes' => lf_int('AI_MAX_PDF_MB', 8) * 1024 * 1024,
        'max_out'       => lf_int('AI_MAX_OUTPUT_TOKENS', 16000),
        'timeout'       => lf_int('AI_TIMEOUT_SECONDS', 180),
    ];
}

/* ── JSON responses ────────────────────────────────────────────────────── */
function lf_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function lf_fail(string $message, int $status = 400, string $code = 'error'): never
{
    lf_json(['ok' => false, 'error' => $message, 'code' => $code], $status);
}

/* ── Storage (rate limits, usage log) — outside git, blocked from the web ─ */
function lf_storage(string $sub): string
{
    $dir = LF_ROOT . '/storage/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $guard = LF_ROOT . '/storage/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nDeny from all\n");
    return $dir;
}

/* Visitors are counted by a salted hash of their IP — the address itself is never stored. */
function lf_client_id(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash('sha256', lf_env('APP_SALT', 'eval360-logframe') . '|' . $ip), 0, 16);
}

/**
 * Daily caps: per visitor and in total. $consume = false only reads.
 * Returns ['ok' => bool, 'mine' => used, 'total' => used, 'reason' => ?string].
 */
function lf_quota(bool $consume): array
{
    $cfg = lf_config();
    $file = lf_storage('ratelimit') . '/' . gmdate('Y-m-d') . '.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return ['ok' => true, 'mine' => 0, 'total' => 0, 'reason' => null];   // never block on a storage fault
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $d = json_decode($raw ?: '{}', true);
    if (!is_array($d)) $d = [];
    $d['total'] = (int) ($d['total'] ?? 0);
    $d['by'] = is_array($d['by'] ?? null) ? $d['by'] : [];
    $id = lf_client_id();
    $mine = (int) ($d['by'][$id] ?? 0);
    $reason = null;
    if ($mine >= $cfg['per_ip_day']) $reason = 'You have reached today\'s limit of ' . $cfg['per_ip_day'] . ' imports. Try again tomorrow, or use the sample logframe.';
    elseif ($d['total'] >= $cfg['total_day']) $reason = 'The demo has reached its daily import limit. Try again tomorrow, or use the sample logframe.';
    if ($consume && $reason === null) {
        $d['by'][$id] = $mine + 1;
        $d['total']++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($d));
        $mine++;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return ['ok' => $reason === null, 'mine' => $mine, 'total' => $d['total'], 'reason' => $reason];
}

/* Token usage per call — for cost tracking. No document content is logged. */
function lf_usage_log(string $model, ?array $usage, bool $ok, string $kind, float $secs): void
{
    $file = lf_storage('logs') . '/usage-' . gmdate('Y-m') . '.csv';
    $new = !is_file($file);
    $fh = @fopen($file, 'a');
    if (!$fh) return;
    if ($new) fputcsv($fh, ['utc', 'visitor', 'model', 'kind', 'input_tokens', 'output_tokens', 'ok', 'seconds'], ',', '"', '\\');
    fputcsv($fh, [gmdate('c'), lf_client_id(), $model, $kind, (int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0), $ok ? 1 : 0, round($secs, 1)], ',', '"', '\\');
    fclose($fh);
}

/* ═══ The extraction contract ══════════════════════════════════════════════
   Strict structured output: every property required, nothing extra. A value
   that is not in the document is null, never guessed. */
function lf_schema(): array
{
    $str = ['type' => 'string'];
    $nstr = ['type' => ['string', 'null']];
    $nnum = ['type' => ['number', 'null']];
    $indicator = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['code', 'name', 'unit', 'baseline', 'baseline_year', 'target', 'target_year', 'direction', 'frequency',
                       'means_of_verification', 'disaggregation', 'source_quote', 'confidence', 'notes'],
        'properties' => [
            'code' => $str,
            'name' => $str,
            'unit' => $str,
            'baseline' => $nnum,
            'baseline_year' => $nstr,
            'target' => $nnum,
            'target_year' => $nstr,
            'direction' => ['type' => 'string', 'enum' => ['increase', 'decrease', 'maintain']],
            'frequency' => $nstr,
            'means_of_verification' => $nstr,
            'disaggregation' => $nstr,
            'source_quote' => $str,
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'notes' => $nstr,
        ],
    ];
    $inds = ['type' => 'array', 'items' => $indicator];
    $output = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['code', 'statement', 'indicators', 'activities', 'assumptions'],
        'properties' => ['code' => $str, 'statement' => $str, 'indicators' => $inds,
                         'activities' => ['type' => 'array', 'items' => $str], 'assumptions' => ['type' => 'array', 'items' => $str]],
    ];
    $outcome = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['code', 'statement', 'indicators', 'outputs', 'assumptions'],
        'properties' => ['code' => $str, 'statement' => $str, 'indicators' => $inds,
                         'outputs' => ['type' => 'array', 'items' => $output], 'assumptions' => ['type' => 'array', 'items' => $str]],
    ];
    return [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['is_logframe', 'not_logframe_reason', 'title', 'organisation', 'country', 'period_start', 'period_end', 'currency',
                       'summary', 'goal', 'outcomes', 'assumptions', 'risks', 'warnings'],
        'properties' => [
            'is_logframe' => ['type' => 'boolean'],
            'not_logframe_reason' => $nstr,
            'title' => $str,
            'organisation' => $nstr,
            'country' => $nstr,
            'period_start' => $nstr,
            'period_end' => $nstr,
            'currency' => $nstr,
            'summary' => $str,
            'goal' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['code', 'statement', 'indicators'],
                'properties' => ['code' => $str, 'statement' => $str, 'indicators' => $inds],
            ],
            'outcomes' => ['type' => 'array', 'items' => $outcome],
            'assumptions' => ['type' => 'array', 'items' => $str],
            'risks' => ['type' => 'array', 'items' => $str],
            'warnings' => ['type' => 'array', 'items' => $str],
        ],
    ];
}

function lf_instructions(): string
{
    return <<<'TXT'
You extract results frameworks for Eval360, a monitoring and evaluation platform.
The user supplies a logical framework (logframe), results framework, theory-of-change table or M&E plan.
Return its structure exactly in the JSON schema.

Hierarchy: goal (also called impact or overall objective) → outcomes (purpose, intermediate results,
specific objectives) → outputs (deliverables, immediate results) → activities.
If the document has a single purpose and several outputs, use one outcome. If it has no explicit goal,
use the highest-level statement as the goal and add a warning.

Fidelity rules — these matter more than completeness:
- Keep the document's own wording for statements and indicator names. Do not paraphrase or improve them.
- Never invent numbers. A baseline or target that is not stated is null, and say so in notes.
  "TBD", "to be determined", "n/a" are null.
- Percentages: number without the % sign, unit "%". Counts: unit describes what is counted
  ("households", "people trained"). Money: unit is the currency.
- Cumulative vs annual targets: use the end-of-programme target; mention annual milestones in notes.
- Codes: use the document's numbering (e.g. "1.2", "Output 2.1", "OC1-IND2"). Where none exists,
  assign G, 1, 1.1, 1.1.1 style codes for results and add ".i1", ".i2" for indicators.
- direction: "decrease" for indicators that should fall (mortality, prevalence, losses, incidents).
- source_quote: a short verbatim excerpt (under 200 characters) where the indicator appears.
- confidence: low when you had to infer the level, the unit or a number.
- Put anything ambiguous, inferred or missing in warnings, in plain language a programme manager understands.
- Assumptions and risks: list them at the level they appear; programme-wide ones go in the top-level arrays.

If the document is not a results framework at all, set is_logframe false, explain in not_logframe_reason,
and return empty arrays and empty strings elsewhere.
TXT;
}

/* ═══ OpenAI — Responses API with strict JSON schema ═══════════════════════ */
function lf_openai_extract(array $userContent): array
{
    $cfg = lf_config();
    $payload = [
        'model' => $cfg['model'],
        'instructions' => lf_instructions(),
        'input' => [['role' => 'user', 'content' => $userContent]],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'logframe', 'strict' => true, 'schema' => lf_schema()]],
        'max_output_tokens' => $cfg['max_out'],
        'store' => false,
    ];
    $t0 = microtime(true);
    $ch = curl_init($cfg['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim((string) lf_env('OPENAI_API_KEY'))],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $secs = microtime(true) - $t0;

    $json = is_string($body) ? json_decode($body, true) : null;
    $ok = $code >= 200 && $code < 300 && is_array($json) && empty($json['error']);
    lf_usage_log($cfg['model'], is_array($json) ? ($json['usage'] ?? null) : null, $ok, isset($userContent[1]) ? 'pdf' : 'text', $secs);

    if ($body === false || $code === 0) {
        lf_fail('Could not reach the AI service. Please try again in a minute.', 502, 'unreachable');
    }
    if (!$ok) {
        // The provider's message can be shown; it never contains the key.
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? 'unknown error') : 'unexpected response';
        $type = is_array($json) ? (string) ($json['error']['code'] ?? $json['error']['type'] ?? '') : '';
        error_log('[logframe] OpenAI HTTP ' . $code . ' ' . $type . ': ' . substr($msg, 0, 300) . ($cerr ? ' curl: ' . $cerr : ''));
        $public = match (true) {
            $code === 401 => 'The AI service rejected the server\'s credentials. The site owner needs to check the API key.',
            $code === 429 => 'The AI service is busy or the account has run out of credit. Try again shortly.',
            $code === 400 && stripos($msg, 'file') !== false => 'The AI service could not read this file. Try saving it as PDF, or paste the text instead.',
            default => 'The AI service returned an error (' . $code . '). Try again, or paste the text instead of uploading.',
        };
        lf_fail($public, 502, 'provider_' . $code);
    }
    return lf_parse_response($json);
}

/* Pull the structured JSON out of a Responses API body. */
function lf_parse_response(array $json): array
{
    if (($json['status'] ?? '') === 'incomplete') {
        $why = (string) ($json['incomplete_details']['reason'] ?? 'unknown');
        lf_fail($why === 'max_output_tokens'
            ? 'This document is too large to extract in one pass. Try uploading only the logframe table or section.'
            : 'The AI stopped before finishing (' . $why . '). Please try again.', 422, 'incomplete');
    }
    $text = null;
    foreach ($json['output'] ?? [] as $item) {
        foreach ($item['content'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'refusal') lf_fail('The AI declined to process this document.', 422, 'refusal');
            if (($c['type'] ?? '') === 'output_text') $text = ($text ?? '') . $c['text'];
        }
    }
    if ($text === null && isset($json['output_text'])) $text = (string) $json['output_text'];
    $data = $text !== null ? json_decode($text, true) : null;
    if (!is_array($data) || !isset($data['goal'], $data['outcomes'])) {
        lf_fail('The AI response could not be read. Please try again.', 502, 'bad_output');
    }
    return $data;
}

/* For local testing without a key (AI_MOCK=1): a fixed, clearly-labelled extraction. */
function lf_mock_extract(string $hint): array
{
    $ind = fn(string $code, string $name, string $unit, ?float $b, ?float $t, string $dir = 'increase', string $conf = 'high', ?string $notes = null) => [
        'code' => $code, 'name' => $name, 'unit' => $unit, 'baseline' => $b, 'baseline_year' => $b === null ? null : '2024', 'target' => $t,
        'target_year' => $t === null ? null : '2027', 'direction' => $dir, 'frequency' => 'Quarterly', 'means_of_verification' => 'Programme monitoring records',
        'disaggregation' => 'Sex, district', 'source_quote' => $name, 'confidence' => $conf, 'notes' => $notes];
    return [
        'is_logframe' => true, 'not_logframe_reason' => null,
        'title' => 'Mock extraction — ' . ($hint !== '' ? $hint : 'pasted text'), 'organisation' => 'Mock organisation', 'country' => null,
        'period_start' => '2025-01-01', 'period_end' => '2027-12-31', 'currency' => 'USD',
        'summary' => 'AI_MOCK is on: this fixed result stands in for a real extraction.',
        'goal' => ['code' => 'G', 'statement' => 'Improved livelihoods of smallholder households', 'indicators' => [
            $ind('G.i1', 'Households with improved food security', '%', 42, 65)]],
        'outcomes' => [[
            'code' => '1', 'statement' => 'Increased agricultural productivity', 'assumptions' => ['Rainfall within normal range'],
            'indicators' => [$ind('1.i1', 'Average maize yield', 'tonnes per hectare', 1.4, 2.2)],
            'outputs' => [[
                'code' => '1.1', 'statement' => 'Farmers trained in climate-smart practices', 'assumptions' => [],
                'indicators' => [$ind('1.1.i1', 'Farmers trained', 'farmers', 0, 12000), $ind('1.1.i2', 'Share of trained farmers who are women', '%', null, 50, 'increase', 'medium', 'Baseline not stated')],
                'activities' => ['Recruit lead farmers', 'Run field schools']]]]],
        'assumptions' => ['Government extension services remain staffed'], 'risks' => ['Input price inflation'],
        'warnings' => ['Mock mode: no AI call was made.', 'Output 1.1 indicator 2 has no baseline.'],
    ];
}
