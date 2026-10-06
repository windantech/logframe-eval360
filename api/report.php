<?php
/**
 * POST api/report.php — the AI report writer.
 * Body (JSON): {"type":"board|quarterly|donor|analysis|quality","audience":"…","length":"short|standard|detailed","instructions":"…","context":{…}}
 * Returns {"ok":true,"report":{title, subtitle, key_messages, sections, recommendations, caveats}}.
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
$type = (string) ($in['type'] ?? '');
if (!isset(LF_REPORT_TYPES[$type])) lf_fail('Unknown report type.', 422, 'type');
$audiences = ['Board', 'Donor', 'Senior management', 'Programme team', 'Government partner'];
$audience = in_array($in['audience'] ?? '', $audiences, true) ? $in['audience'] : 'Senior management';
$length = in_array($in['length'] ?? '', ['short', 'standard', 'detailed'], true) ? $in['length'] : 'standard';
$extra = trim(mb_substr((string) ($in['instructions'] ?? ''), 0, 800));
$data = lf_ai_context($in['context'] ?? null);

$q = lf_quota(true);
if (!$q['ok']) lf_fail((string) $q['reason'], 429, 'quota');
@set_time_limit($cfg['timeout'] + 30);

$report = $cfg['mock'] ? lf_mock_report($in['context'], $type)
    : lf_openai_call(lf_report_instructions($type, $audience, $length, $extra, $data),
        [['type' => 'input_text', 'text' => 'Write the report from the data provided.']], 'eval360_report', lf_report_schema(), 'report',
        ['title', 'sections', 'key_messages'], $length === 'detailed' ? 9000 : 6000);

lf_json(['ok' => true, 'report' => $report, 'meta' => ['model' => $cfg['mock'] ? 'mock' : $cfg['model'], 'at' => gmdate('c'), 'remaining_today' => max(0, $cfg['per_ip_day'] - $q['mine'])]]);
