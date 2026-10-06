<?php
/**
 * POST api/extract.php — logframe document in, structured framework out.
 *
 * Body (JSON): {"kind":"text","text":"…","filename":"…"}
 *          or  {"kind":"pdf","file_base64":"…","filename":"…"}
 * The browser converts Word and Excel files to text first: the host has no
 * ZipArchive, so .docx/.xlsx cannot be opened here.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lf_fail('POST only.', 405, 'method');

// Same-origin only: the page that calls this is served from this site.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    lf_fail('Cross-site requests are not accepted.', 403, 'origin');
}

$cfg = lf_config();
if (!$cfg['key_set'] && !$cfg['mock']) lf_fail('AI import is not configured on this server yet. Use the sample logframe.', 503, 'not_configured');

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    lf_fail('The upload arrived empty — usually the file is larger than the server accepts. Try a smaller PDF, or paste the text.', 413, 'empty');
}
$in = json_decode($raw, true);
if (!is_array($in)) lf_fail('Malformed request.', 400, 'json');

$kind = (string) ($in['kind'] ?? '');
$filename = substr(preg_replace('/[^\w .()\-]+/u', '_', (string) ($in['filename'] ?? 'document')) ?? 'document', 0, 120);

if ($kind === 'text') {
    $text = trim((string) ($in['text'] ?? ''));
    if (mb_strlen($text) < 80) lf_fail('There is not enough text to read a logframe from. Paste the whole results framework.', 422, 'too_short');
    if (mb_strlen($text) > $cfg['max_chars']) lf_fail('The text is longer than ' . number_format($cfg['max_chars']) . ' characters. Upload only the logframe section.', 413, 'too_long');
    $content = [['type' => 'input_text', 'text' => "Document: {$filename}\n\n" . $text]];
} elseif ($kind === 'pdf') {
    $b64 = (string) ($in['file_base64'] ?? '');
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 100) lf_fail('The PDF could not be read.', 422, 'bad_pdf');
    if (strlen($bin) > $cfg['max_pdf_bytes']) lf_fail('The PDF is larger than ' . round($cfg['max_pdf_bytes'] / 1048576) . ' MB. Upload only the pages with the logframe.', 413, 'pdf_too_big');
    if (!str_starts_with($bin, '%PDF')) lf_fail('That file is not a PDF.', 422, 'not_pdf');
    $content = [
        ['type' => 'input_text', 'text' => "Extract the results framework from the attached document ({$filename})."],
        ['type' => 'input_file', 'filename' => $filename, 'file_data' => 'data:application/pdf;base64,' . $b64],
    ];
} else {
    lf_fail('Unknown request kind.', 400, 'kind');
}

$q = lf_quota(true);
if (!$q['ok']) lf_fail((string) $q['reason'], 429, 'quota');

@set_time_limit($cfg['timeout'] + 30);
$data = $cfg['mock'] ? lf_mock_extract($filename) : lf_openai_extract($content);

lf_json([
    'ok' => true,
    'framework' => $data,
    'meta' => ['model' => $cfg['mock'] ? 'mock' : $cfg['model'], 'filename' => $filename, 'extracted_at' => gmdate('c'),
               'remaining_today' => max(0, $cfg['per_ip_day'] - $q['mine'])],
]);
