<?php
/**
 * POST api/draft.php — draft a logframe for a programme that has none.
 * Body (JSON): {"org":{name,sector,country,type,summary,mission},"programme":{name,description,location,target_group,period_start,period_end}}
 * Returns a framework in the same shape as api/extract.php, labelled as a proposal.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_org.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lf_fail('POST only.', 405, 'method');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) lf_fail('Cross-site requests are not accepted.', 403, 'origin');
$cfg = lf_config();
if (!$cfg['key_set'] && !$cfg['mock']) lf_fail('AI is not configured on this server yet.', 503, 'not_configured');

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in) || !is_array($in['org'] ?? null) || !is_array($in['programme'] ?? null)) lf_fail('Malformed request.', 400, 'json');
$clip = fn($v, $n) => trim(mb_substr(is_string($v) ? $v : '', 0, $n));
$org = $in['org']; $prog = $in['programme'];
$pname = $clip($prog['name'] ?? '', 160);
if (mb_strlen($pname) < 3) lf_fail('The programme needs a name.', 422, 'name');

$q = lf_quota(true);
if (!$q['ok']) lf_fail((string) $q['reason'], 429, 'quota');
@set_time_limit($cfg['timeout'] + 30);

if ($cfg['mock']) {
    $fw = lf_mock_draft($pname);
} else {
    $text = "ORGANISATION\nName: " . $clip($org['name'] ?? '', 160) . "\nType: " . $clip($org['type'] ?? '', 60) . "\nSector: " . $clip($org['sector'] ?? '', 120)
          . "\nCountry: " . $clip($org['country'] ?? '', 80) . "\nSummary: " . $clip($org['summary'] ?? '', 1200) . "\nMission: " . $clip($org['mission'] ?? '', 600)
          . "\n\nPROGRAMME\nName: {$pname}\nDescription: " . $clip($prog['description'] ?? '', 1500) . "\nLocation: " . $clip($prog['location'] ?? '', 160)
          . "\nTarget group: " . $clip($prog['target_group'] ?? '', 160) . "\nPeriod: " . $clip($prog['period_start'] ?? '', 20) . ' to ' . $clip($prog['period_end'] ?? '', 20);
    $fw = lf_openai_call(lf_draft_instructions(), [['type' => 'input_text', 'text' => $text]], 'logframe', lf_schema(), 'draft', ['goal', 'outcomes']);
}
lf_json(['ok' => true, 'framework' => $fw,
    'meta' => ['model' => $cfg['mock'] ? 'mock' : $cfg['model'], 'generated_at' => gmdate('c'), 'remaining_today' => max(0, $cfg['per_ip_day'] - $q['mine'])]]);
