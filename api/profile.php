<?php
/**
 * POST api/profile.php — organisation profile from its website and/or a description.
 * Body (JSON): {"name":"…","website":"…","description":"…","country":"…"}
 * Returns the profile, the pages read (URL + title) and brand hints (colours, logo).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_web.php';
require_once __DIR__ . '/lib_org.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lf_fail('POST only.', 405, 'method');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) lf_fail('Cross-site requests are not accepted.', 403, 'origin');
$cfg = lf_config();
if (!$cfg['key_set'] && !$cfg['mock']) lf_fail('AI is not configured on this server yet. Use the sample organisation.', 503, 'not_configured');

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) lf_fail('Malformed request.', 400, 'json');
$name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 160));
$desc = trim(mb_substr((string) ($in['description'] ?? ''), 0, 6000));
$country = trim(mb_substr((string) ($in['country'] ?? ''), 0, 80));
if (mb_strlen($name) < 2) lf_fail('Enter the organisation\'s name.', 422, 'name');

$site = ['pages' => [], 'brand' => null, 'error' => null];
$web = lf_web_normalise((string) ($in['website'] ?? ''));
if (($in['website'] ?? '') !== '' && $web === null) $site['error'] = 'The website address is not valid.';
$q = lf_quota(true);
if (!$q['ok']) lf_fail((string) $q['reason'], 429, 'quota');
@set_time_limit($cfg['timeout'] + 90);
if ($web !== null) $site = lf_web_read($web);

if ($cfg['mock']) {
    $profile = lf_mock_profile($name, $site['pages'], $desc);
} else {
    $text = "Organisation name: {$name}\n" . ($country !== '' ? "Country (from the visitor): {$country}\n" : '')
          . ($desc !== '' ? "\nDescription typed by the visitor:\n{$desc}\n" : '')
          . ($site['error'] ? "\nThe website could not be read: {$site['error']}\n" : '')
          . ($site['pages'] ? "\nPages read from the organisation's website:\n" : "\nNo website pages were read.\n");
    foreach ($site['pages'] as $p) $text .= "\n=== PAGE: {$p['url']}\nTitle: {$p['title']}\n{$p['text']}\n";
    $profile = lf_openai_call(lf_profile_instructions(), [['type' => 'input_text', 'text' => $text]], 'org_profile', lf_profile_schema(), 'profile',
        ['org', 'facts', 'strategic_goals', 'programmes']);
}
// A fact may only cite a page that was actually read.
$read = array_column($site['pages'], 'url');
foreach ($profile['facts'] as &$f) if ($f['source_url'] !== null && !in_array($f['source_url'], $read, true)) $f['source_url'] = null;
unset($f);
foreach ($profile['programmes'] as &$p) if ($p['source_url'] !== null && !in_array($p['source_url'], $read, true)) { $p['source_url'] = null; }
unset($p);

lf_json(['ok' => true, 'profile' => $profile,
    'site' => ['url' => $web, 'error' => $site['error'], 'pages' => array_map(fn($p) => ['url' => $p['url'], 'title' => $p['title']], $site['pages']), 'brand' => $site['brand']],
    'meta' => ['model' => $cfg['mock'] ? 'mock' : $cfg['model'], 'generated_at' => gmdate('c'), 'remaining_today' => max(0, $cfg['per_ip_day'] - lf_quota(false)['mine'])]]);
