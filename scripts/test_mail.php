#!/usr/bin/env php
<?php
/**
 * Send one test message, using whatever this installation is configured to use.
 *
 *   php scripts/test_mail.php you@example.com
 *
 * There is no login on the demo, so the link we email someone is their only way
 * back into their own work. That makes "does mail actually arrive, in the
 * inbox" a deployment requirement, not a nicety — and the only way to know is
 * to send one and look.
 *
 * Command line only. It prints which transport it used and which settings are
 * present; it never prints the password.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}
define('LF_ROOT', dirname(__DIR__));
require LF_ROOT . '/api/lib_mail.php';

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/test_mail.php you@example.com\n");
    exit(2);
}

$c = lf_mail_config();
$smtp = lf_mail_smtp_ready();
echo "Transport : " . ($smtp ? "SMTP {$c['host']}:{$c['port']} ({$c['secure']})" : "PHP mail() — no SMTP_HOST set") . "\n";
echo "From      : " . ($c['from'] ?: '(not set)') . "\n";
echo "Reply-To  : " . ($c['reply'] ?: '(not set)') . "\n";
echo "Auth      : " . ($c['user'] ? $c['user'] . ' / password ' . ($c['pass'] ? 'set' : 'NOT SET') : 'none') . "\n";
echo "To        : $to\n\n";

if (!$smtp) {
    echo "Warning: without SMTP this sends as the web server's own address, which the\n";
    echo "         domain's SPF record does not authorise. Expect the spam folder.\n\n";
}

$body = "This is a test from the Eval360 demo site.\n\n"
      . "If you are reading it in your inbox — not the spam folder — then the demo\n"
      . "links people are emailed will reach them, and they can get back into their\n"
      . "own demo from any device.\n\n"
      . "Sent " . gmdate('c') . " UTC from " . (gethostname() ?: 'this server') . ".\n";

$t0 = microtime(true);
$ok = lf_mail($to, 'Eval360 demo — mail test', $body, 'Eval360 test');
$ms = round((microtime(true) - $t0) * 1000);

if ($ok) {
    echo "SENT in {$ms}ms — accepted for delivery.\n\n";
    echo "Now go and look. Accepted is not the same as delivered:\n";
    echo "  · If it is in the inbox, you are done.\n";
    echo "  · If it is in spam, check SPF and DKIM for the From: domain above.\n";
    echo "  · If it never arrives, check the mailbox's own logs.\n";
    exit(0);
}
echo "NOT SENT after {$ms}ms.\n\n";
echo "The reason was written to the PHP error log (look for '[logframe] SMTP').\n";
echo "Set SMTP_DEBUG=1 in the key file to have the conversation logged too —\n";
echo "credentials are withheld from that log.\n";
exit(1);
