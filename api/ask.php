<?php
/**
 * POST api/ask.php — Ask Eval360: a question about the organisation's data.
 * Body (JSON): {"question":"…","history":[{"role":"user"|"assistant","content":"…"}],"context":{organisation, programmes, …}}
 * Returns {"ok":true,"answer":"markdown"}.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_ai.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lf_fail('POST only.', 405, 'method');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) lf_fail('Cross-site requests are not accepted.', 403, 'origin');
$cfg = lf_config();
if (!$cfg['key_set'] && !$cfg['mock']) lf_fail('AI is not configured on this server yet.', 503, 'not_configured');

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) lf_fail('Malformed request.', 400, 'json');
$question = trim((string) ($in['question'] ?? ''));
if (mb_strlen($question) < 3) lf_fail('Type a question.', 422, 'question');
if (mb_strlen($question) > LF_AI_MAX_QUESTION) lf_fail('Please keep the question under ' . LF_AI_MAX_QUESTION . ' characters.', 413, 'question_size');
$data = lf_ai_context($in['context'] ?? null);
// Earlier turns: alternating user / assistant text only, most recent kept.
$history = [];
foreach (array_slice(is_array($in['history'] ?? null) ? $in['history'] : [], -LF_AI_MAX_TURNS) as $h) {
    $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = mb_substr(trim((string) ($h['content'] ?? '')), 0, 4000);
    if ($content !== '') $history[] = ['role' => $role, 'content' => $content];
}

$q = lf_quota(true);
if (!$q['ok']) lf_fail((string) $q['reason'], 429, 'quota');
@set_time_limit($cfg['timeout'] + 30);

$answer = $cfg['mock'] ? lf_mock_answer($in['context'], $question)
    : lf_openai_text(lf_ask_instructions($data), array_merge($history, [['role' => 'user', 'content' => $question]]), 'ask', 1800);

lf_json(['ok' => true, 'answer' => $answer, 'meta' => ['model' => $cfg['mock'] ? 'mock' : $cfg['model'], 'at' => gmdate('c'), 'remaining_today' => max(0, $cfg['per_ip_day'] - $q['mine'])]]);
