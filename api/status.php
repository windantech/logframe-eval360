<?php
/** GET api/status.php — is AI import available, and how many imports this visitor has left today. Never reveals the key. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$cfg = lf_config();
$q = lf_quota(false);
lf_json([
    'ok' => true,
    'ai' => $cfg['key_set'] || $cfg['mock'],
    'mock' => $cfg['mock'],
    'model' => $cfg['mock'] ? 'mock' : $cfg['model'],
    'remaining_today' => max(0, $cfg['per_ip_day'] - $q['mine']),
    'limits' => ['per_visitor_day' => $cfg['per_ip_day'], 'max_pdf_mb' => (int) round($cfg['max_pdf_bytes'] / 1048576), 'max_text_chars' => $cfg['max_chars']],
]);
