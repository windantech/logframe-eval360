<?php
/**
 * Eval360 demo sharing — the store behind step two of the sales process:
 * "we will create a system demo, and send you a link to review it".
 *
 * A visitor sets a demo up in their browser as before. When they ask for a
 * link, the reviewed set-up (and only that) is written here so the link works
 * for anyone they send it to. The documents it was extracted from are still
 * never stored — see lf_share_clean().
 *
 * Storage is one JSON file per demo under storage/, locked with flock — the
 * same pattern lf_quota() already uses, and for the same reason: it needs no
 * database driver, no credentials and no server configuration, so a
 * deployment never has to touch a connection string or an env file. At demo
 * traffic (a handful of links a day) a directory of files is the right size of
 * machinery; if this ever outgrows it, the lf_share_* functions are the seam.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/* ── Configuration ───────────────────────────────────────────────────────── */
function lf_share_config(): array
{
    return [
        'enabled'       => lf_env('SHARE_DISABLE') !== '1',
        'ttl_days'      => lf_int('SHARE_TTL_DAYS', 90),
        'per_ip_day'    => lf_int('SHARE_LIMIT_PER_IP_DAY', 10),
        'max_bytes'     => lf_int('SHARE_MAX_PAYLOAD_KB', 1500) * 1024,
        'gate'          => lf_env('SHARE_GATE', '1') === '1',
        'meeting_url'   => lf_env('SHARE_MEETING_URL'),
        'contact_email' => lf_env('SHARE_CONTACT_EMAIL', 'eval360@vantageafricaleaders.com'),
        'contact_phone' => lf_env('SHARE_CONTACT_PHONE', '0105064544'),
        'notify'        => lf_env('SHARE_NOTIFY_EMAIL'),
        'notify_from'   => lf_env('SHARE_NOTIFY_FROM'),
        'admin_key'     => lf_env('SHARE_ADMIN_KEY'),
    ];
}

function lf_share_dir(): string
{
    return lf_storage('share/demos');
}
function lf_share_file(string $slug): string
{
    return lf_share_dir() . '/' . $slug . '.json';
}

/* ── Identifiers ─────────────────────────────────────────────────────────── */
/**
 * The link is the credential, so the slug has to be unguessable: 12 characters
 * of base32 is 60 bits. Crockford's alphabet — no I, L, O or U, so a slug read
 * out down the phone does not come back wrong.
 */
function lf_share_slug(): string
{
    $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
    $out = '';
    foreach (str_split(bin2hex(random_bytes(12)), 2) as $byte) $out .= $alphabet[hexdec($byte) % 32];
    return substr($out, 0, 12);
}
function lf_share_token(): string
{
    return bin2hex(random_bytes(16));
}
/** Proof that this browser answered the contact form, so it is asked once and not again. */
function lf_share_pass(string $slug, int $leadId): string
{
    return $leadId . '.' . substr(hash_hmac('sha256', 'pass|' . $slug . '|' . $leadId, (string) lf_env('APP_SALT', 'eval360-logframe')), 0, 24);
}
function lf_share_pass_ok(string $slug, string $pass): bool
{
    $parts = explode('.', $pass, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return false;
    return hash_equals(lf_share_pass($slug, (int) $parts[0]), $pass);
}
function lf_share_lead_id(string $pass): ?int
{
    return str_contains($pass, '.') && ctype_digit(explode('.', $pass)[0]) ? (int) explode('.', $pass)[0] : null;
}

/* ── Reading and writing one demo, under a lock ──────────────────────────── */
function lf_share_valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9]{12}$/', $slug);
}

/** Read a demo, or null if it is missing, unreadable or past its expiry. */
function lf_share_find(string $slug): ?array
{
    if (!lf_share_valid_slug($slug)) return null;
    $file = lf_share_file($slug);
    if (!is_file($file)) return null;
    $d = json_decode((string) @file_get_contents($file), true);
    if (!is_array($d) || !isset($d['slug'])) return null;
    if (($d['expires_at'] ?? '') < gmdate('c')) {                // an expired demo reads as gone, and the sweep removes the file
        @unlink($file);
        return null;
    }
    return $d;
}

/**
 * Read-modify-write under an exclusive lock. $fn gets the demo and returns it
 * changed; returning null leaves the file alone. Concurrent opens of the same
 * link are the common case, and both want to bump the open count.
 */
function lf_share_edit(string $slug, callable $fn): ?array
{
    if (!lf_share_valid_slug($slug)) return null;
    $file = lf_share_file($slug);
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    try {
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $d = json_decode($raw ?: 'null', true);
        if (!is_array($d) || !isset($d['slug'])) return null;
        $out = $fn($d);
        if (!is_array($out)) return $d;
        $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return $d;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
        return $out;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * What gets stored. The whole point is that this is the *reviewed set-up*, not
 * the documents behind it: uploads, payloads and pasted text are dropped here
 * as well as in the browser, so a stored demo can never carry a copy of the
 * visitor's source document.
 */
function lf_share_clean(array $p): array
{
    $keep = ['v', 'org', 'programmes', 'current', 'overrides', 'log', 'settings', 'chat', 'aiReports', 'aiScope'];
    $out = [];
    foreach ($keep as $k) if (array_key_exists($k, $p)) $out[$k] = $p[$k];
    unset($out['setup']);                                        // the wizard's working state, which is where uploads live
    if (isset($out['org']['source']) && is_array($out['org']['source'])) unset($out['org']['source']['text']);
    if (is_array($out['programmes'] ?? null)) {
        foreach ($out['programmes'] as $i => $prog) {
            if (!is_array($prog)) continue;
            unset($out['programmes'][$i]['payload']);
            if (isset($prog['source']) && is_array($prog['source'])) {
                unset($out['programmes'][$i]['source']['text'], $out['programmes'][$i]['source']['payload']);
            }
        }
    }
    return $out;
}

function lf_share_encode(array $payload): string
{
    $cfg = lf_share_config();
    $json = json_encode(lf_share_clean($payload), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) lf_fail('This set-up could not be saved. Please try again.', 422, 'encode');
    if (strlen($json) > $cfg['max_bytes']) {
        lf_fail('This set-up is too large to share (' . round(strlen($json) / 1024) . ' KB; the limit is ' . round($cfg['max_bytes'] / 1024) . ' KB). Share fewer programmes.', 413, 'too_large');
    }
    return $json;
}

function lf_share_create(array $payload, ?array $lead, ?string $parent = null): array
{
    $cfg = lf_share_config();
    $clean = lf_share_clean($payload);
    lf_share_encode($payload);                                   // size and encoding are checked before anything is written
    $now = gmdate('c');
    $slug = lf_share_slug();
    $demo = [
        'slug' => $slug,
        'edit_token' => lf_share_token(),
        'org_name' => mb_substr((string) ($clean['org']['name'] ?? ''), 0, 160),
        'programmes' => is_array($clean['programmes'] ?? null) ? count($clean['programmes']) : 0,
        'payload' => $clean,
        'owner_lead' => null,
        'parent' => $parent,
        'revision' => 1,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('c', time() + max(1, $cfg['ttl_days']) * 86400),
        'opens' => 0,
        'last_open_at' => null,
        'ip_hash' => lf_client_id(),
        'lead_seq' => 0,
        'leads' => [],
        'events' => [],
    ];
    if ($lead !== null) {
        $demo['lead_seq'] = 1;
        $demo['leads'][] = lf_share_lead_row(1, 'owner', $lead);
        $demo['owner_lead'] = 1;
    }
    $json = json_encode($demo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || @file_put_contents(lf_share_file($slug), $json, LOCK_EX) === false) {
        lf_fail('The demo could not be saved just now. Please try again in a minute.', 503, 'store');
    }
    lf_share_count_create();
    if (random_int(1, 20) === 1) lf_share_prune();
    return $demo;
}

function lf_share_update(string $slug, array $payload): ?array
{
    lf_share_encode($payload);                                   // reject an oversized payload before taking the lock
    $clean = lf_share_clean($payload);
    return lf_share_edit($slug, function (array $d) use ($clean) {
        $d['payload'] = $clean;
        $d['org_name'] = mb_substr((string) ($clean['org']['name'] ?? ''), 0, 160);
        $d['programmes'] = is_array($clean['programmes'] ?? null) ? count($clean['programmes']) : 0;
        $d['revision'] = (int) ($d['revision'] ?? 1) + 1;
        $d['updated_at'] = gmdate('c');
        return $d;
    });
}

/** Expired demos are deleted outright — there is no archive to leak. */
function lf_share_prune(): void
{
    $now = gmdate('c');
    foreach (glob(lf_share_dir() . '/*.json') ?: [] as $file) {
        $d = json_decode((string) @file_get_contents($file), true);
        if (!is_array($d) || ($d['expires_at'] ?? '') < $now) @unlink($file);
    }
    foreach (glob(lf_storage('share') . '/creates-*.json') ?: [] as $file) {
        if (@filemtime($file) < time() - 3 * 86400) @unlink($file);
    }
}

/* ── Leads and events ────────────────────────────────────────────────────── */
function lf_share_lead_row(int $id, string $kind, array $in): array
{
    return [
        'id' => $id,
        'kind' => $kind === 'owner' ? 'owner' : 'viewer',
        'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120),
        'email' => mb_substr(trim((string) ($in['email'] ?? '')), 0, 160),
        'org' => mb_substr(trim((string) ($in['org'] ?? '')), 0, 160),
        'role' => mb_substr(trim((string) ($in['role'] ?? '')), 0, 120),
        'created_at' => gmdate('c'),
        'ip_hash' => lf_client_id(),
    ];
}

/** Add a contact to a demo and return its id, or null if the demo is gone. */
function lf_share_lead(string $slug, string $kind, array $in): ?int
{
    $id = null;
    lf_share_edit($slug, function (array $d) use ($kind, $in, &$id) {
        // A contact that is already on this demo is not duplicated; a second
        // visit from the same person should not look like a second person.
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        foreach ($d['leads'] ?? [] as $l) {
            if (strtolower((string) $l['email']) === $email && $email !== '') { $id = (int) $l['id']; return null; }
        }
        $id = (int) ($d['lead_seq'] ?? 0) + 1;
        $d['lead_seq'] = $id;
        $d['leads'][] = lf_share_lead_row($id, $kind, $in);
        return $d;
    });
    return $id;
}

const LF_SHARE_EVENTS = ['created', 'opened', 'gated', 'edited', 'forked', 'meeting', 'exported'];
const LF_SHARE_EVENT_CAP = 400;

function lf_share_event(string $slug, string $kind, string $detail = '', ?int $leadId = null): void
{
    if (!in_array($kind, LF_SHARE_EVENTS, true)) return;
    lf_share_edit($slug, function (array $d) use ($kind, $detail, $leadId) {
        $d['events'][] = ['kind' => $kind, 'detail' => mb_substr($detail, 0, 200), 'lead' => $leadId, 'at' => gmdate('c'), 'ip_hash' => lf_client_id()];
        if (count($d['events']) > LF_SHARE_EVENT_CAP) $d['events'] = array_slice($d['events'], -LF_SHARE_EVENT_CAP);
        if ($kind === 'opened') {
            $d['opens'] = (int) ($d['opens'] ?? 0) + 1;
            $d['last_open_at'] = gmdate('c');
        }
        return $d;
    });
}

function lf_share_find_lead(array $demo, ?int $id): ?array
{
    foreach ($demo['leads'] ?? [] as $l) if ((int) $l['id'] === (int) $id) return $l;
    return null;
}

/* ── Abuse protection ────────────────────────────────────────────────────── */
/** Creates per visitor per day — this endpoint writes but makes no AI call, so it needs its own cap. */
function lf_share_count_file(): string
{
    return lf_storage('share') . '/creates-' . gmdate('Y-m-d') . '.json';
}
function lf_share_rate_ok(): bool
{
    $cfg = lf_share_config();
    if ($cfg['per_ip_day'] <= 0) return true;
    $d = json_decode((string) @file_get_contents(lf_share_count_file()), true);
    return (int) (is_array($d) ? ($d[lf_client_id()] ?? 0) : 0) < $cfg['per_ip_day'];
}
function lf_share_count_create(): void
{
    $fh = @fopen(lf_share_count_file(), 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $d = json_decode(stream_get_contents($fh) ?: '{}', true);
    if (!is_array($d)) $d = [];
    $id = lf_client_id();
    $d[$id] = (int) ($d[$id] ?? 0) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN);
    fclose($fh);
}

/* ── Notifying Vantage ───────────────────────────────────────────────────── */
/**
 * Step two only works if somebody follows it up, so a new demo, a first open
 * and a meeting click are mailed to SHARE_NOTIFY_EMAIL. The notice carries who
 * and when — never any of the stored framework.
 */
function lf_share_notify(string $subject, array $lines): void
{
    $cfg = lf_share_config();
    if (!$cfg['notify'] || !function_exists('mail')) return;
    $body = implode("\n", $lines) . "\n\n-- \nEval360 demo pipeline\n";
    $headers = ['Content-Type: text/plain; charset=utf-8'];
    if ($cfg['notify_from']) $headers[] = 'From: Eval360 demos <' . $cfg['notify_from'] . '>';
    @mail($cfg['notify'], '[Eval360 demo] ' . $subject, $body, implode("\r\n", $headers));
}

function lf_share_public_url(string $slug): string
{
    $base = lf_env('SHARE_BASE_URL');
    if (!$base) {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/share.php'))), '/');
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/logframe_eval360_demo.html';
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . 'demo=' . $slug;
}

/** Every demo on the server, newest activity first — for the pipeline page only. */
function lf_share_all(int $limit = 200): array
{
    $out = [];
    foreach (glob(lf_share_dir() . '/*.json') ?: [] as $file) {
        $d = json_decode((string) @file_get_contents($file), true);
        if (!is_array($d) || !isset($d['slug'])) continue;
        unset($d['payload']);                                    // the pipeline page never needs the framework
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp((string) ($b['last_open_at'] ?: $b['created_at']), (string) ($a['last_open_at'] ?: $a['created_at'])));
    return array_slice($out, 0, $limit);
}
