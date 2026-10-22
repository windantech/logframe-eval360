<?php
/* TEST ONLY — sends one message through api/lib_mail.php, for tests/smtp_test.py. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not found.\n"); }
define('LF_ROOT', $argv[1]);
require $argv[1] . '/api/lib_mail.php';
echo lf_mail($argv[2], $argv[3], $argv[4], $argv[5] ?? '') ? "SENT\n" : "NOTSENT\n";
