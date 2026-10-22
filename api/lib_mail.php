<?php
/**
 * Sending mail — SMTP, or PHP's mail() when no SMTP host is configured.
 *
 * Why this exists rather than a library: there is no login on this demo, so the
 * link we email someone is their only way back into their own work. PHP's
 * mail() sends as the host's default address, which the domain's SPF record
 * does not authorise, and the message lands in spam. Relaying through the same
 * authenticated mailbox the rest of Eval360 uses (mail.eval360.tech) means the
 * mail actually originates from the domain and arrives.
 *
 * Why not reuse the MEL system's SwiftMailer: it is reached through a Composer
 * tree belonging to a separately deployed application, and its credentials sit
 * in an env file that also holds database passwords and payment tokens. A
 * public demo has no business being able to read that file. This speaks SMTP
 * directly in about a hundred lines, keeps its own credentials, and keeps the
 * project's no-Composer rule.
 *
 * The password is never logged, never returned and never shown to a visitor.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function lf_mail_config(): array
{
    $port = lf_int('SMTP_PORT', 587);
    return [
        'host'   => lf_env('SMTP_HOST'),
        'port'   => $port,
        'user'   => lf_env('SMTP_USER'),
        'pass'   => lf_env('SMTP_PASS'),
        // 465 is TLS from the first byte; 587 upgrades with STARTTLS.
        'secure' => strtolower((string) lf_env('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls')),
        // SHARE_NOTIFY_FROM is the older name for this and still works.
        'from'   => lf_env('SMTP_FROM', lf_env('SMTP_USER', lf_env('SHARE_NOTIFY_FROM'))),
        'name'   => lf_env('SMTP_FROM_NAME', 'Eval360'),
        'reply'  => lf_env('SHARE_CONTACT_EMAIL', 'eval360@vantageafricaleaders.com'),
        'timeout' => lf_int('SMTP_TIMEOUT', 20),
        'debug'  => lf_env('SMTP_DEBUG') === '1',
    ];
}

/** True when mail will go out over SMTP rather than the host's mail(). */
function lf_mail_smtp_ready(): bool
{
    $c = lf_mail_config();
    return $c['host'] !== null && $c['from'] !== null;
}

/**
 * Send one plain-text message. Returns true only if it was accepted for
 * delivery. $to is a bare address; $toName is optional.
 */
function lf_mail(string $to, string $subject, string $body, string $toName = ''): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $c = lf_mail_config();
    if (lf_mail_smtp_ready()) return lf_mail_smtp($to, $toName, $subject, $body, $c);

    // No SMTP configured: fall back, so a fresh install still works.
    if (!function_exists('mail')) return false;
    $headers = ['Content-Type: text/plain; charset=utf-8', 'MIME-Version: 1.0'];
    if ($c['from']) $headers[] = 'From: ' . lf_mail_addr($c['from'], (string) $c['name']);
    if ($c['reply']) $headers[] = 'Reply-To: ' . $c['reply'];
    return @mail($to, lf_mail_header($subject), str_replace(["\r\n", "\r"], "\n", $body), implode("\r\n", $headers));
}

/* ── Header helpers ──────────────────────────────────────────────────────── */
/** RFC 2047 for anything that is not plain ASCII; a header may never span lines. */
function lf_mail_header(string $v): string
{
    $v = str_replace(["\r", "\n"], ' ', trim($v));
    return preg_match('/^[\x20-\x7E]*$/', $v) ? $v : '=?UTF-8?B?' . base64_encode($v) . '?=';
}
function lf_mail_addr(string $addr, string $name = ''): string
{
    $addr = str_replace(["\r", "\n", '<', '>'], '', $addr);
    return $name === '' ? $addr : lf_mail_header($name) . ' <' . $addr . '>';
}

/* ── SMTP ────────────────────────────────────────────────────────────────── */
function lf_mail_smtp(string $to, string $toName, string $subject, string $body, array $c): bool
{
    $scheme = $c['secure'] === 'ssl' ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($scheme . $c['host'] . ':' . $c['port'], $errno, $errstr, $c['timeout'],
        STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['SNI_enabled' => true]]));
    if (!$fp) {
        error_log('[logframe] SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }
    stream_set_timeout($fp, $c['timeout']);

    $log = [];
    // Returns the reply, or null when the code is not what we need. The
    // conversation is kept for the error log — minus anything we sent after
    // AUTH, which is the password.
    $talk = function (?string $line, array $expect) use ($fp, &$log, $c): ?string {
        if ($line !== null) {
            fwrite($fp, $line . "\r\n");
            $log[] = '> ' . (preg_match('/^(AUTH|[A-Za-z0-9+\/=]{12,}$)/', $line) ? '[credentials withheld]' : substr($line, 0, 120));
        }
        $reply = '';
        while (!feof($fp)) {
            $r = fgets($fp, 2048);
            if ($r === false) break;
            $reply .= $r;
            if (strlen($r) >= 4 && $r[3] === ' ') break;      // last line of a multiline reply
        }
        $log[] = '< ' . substr(trim($reply), 0, 160);
        return in_array((int) substr($reply, 0, 3), $expect, true) ? $reply : null;
    };
    $fail = function (string $why) use ($fp, &$log, $c): bool {
        error_log('[logframe] SMTP ' . $why . ($c['debug'] ? ' :: ' . implode(' | ', $log) : ''));
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    };

    $ehlo = 'EHLO ' . (lf_env('SMTP_EHLO') ?: (gethostname() ?: 'localhost'));
    if ($talk(null, [220]) === null) return $fail('no greeting');
    $caps = $talk($ehlo, [250]);
    if ($caps === null) return $fail('EHLO refused');

    if ($c['secure'] === 'tls') {
        if (stripos($caps, 'STARTTLS') === false) return $fail('server does not offer STARTTLS; set SMTP_SECURE=none only on a trusted network');
        if ($talk('STARTTLS', [220]) === null) return $fail('STARTTLS refused');
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$ok) return $fail('TLS handshake failed');
        $caps = $talk($ehlo, [250]);                           // capabilities are re-read inside TLS
        if ($caps === null) return $fail('EHLO after STARTTLS refused');
    }

    if ($c['user'] !== null && $c['pass'] !== null) {
        if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') !== false) {
            if ($talk('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), [235]) === null) return $fail('authentication refused');
        } else {
            if ($talk('AUTH LOGIN', [334]) === null) return $fail('AUTH LOGIN refused');
            if ($talk(base64_encode($c['user']), [334]) === null) return $fail('username refused');
            if ($talk(base64_encode($c['pass']), [235]) === null) return $fail('authentication refused');
        }
    }

    if ($talk('MAIL FROM:<' . $c['from'] . '>', [250]) === null) return $fail('sender refused');
    if ($talk('RCPT TO:<' . $to . '>', [250, 251]) === null) return $fail('recipient refused');
    if ($talk('DATA', [354]) === null) return $fail('DATA refused');

    fwrite($fp, lf_mail_message($to, $toName, $subject, $body, $c));
    if ($talk('.', [250]) === null) return $fail('message rejected');

    $talk('QUIT', [221]);
    fclose($fp);
    return true;
}

/** The message itself: CRLF throughout, base64 body, and dots that cannot end it early. */
function lf_mail_message(string $to, string $toName, string $subject, string $body, array $c): string
{
    $domain = substr(strrchr((string) $c['from'], '@') ?: '@localhost', 1);
    $h = [
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'From: ' . lf_mail_addr((string) $c['from'], (string) $c['name']),
        'To: ' . lf_mail_addr($to, $toName),
        'Subject: ' . lf_mail_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
    ];
    if ($c['reply']) $h[] = 'Reply-To: ' . $c['reply'];
    // Base64 sidesteps 8-bit and long-line limits entirely, which also means no
    // line of the body can ever be a lone "." and end the message early.
    return implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
}
