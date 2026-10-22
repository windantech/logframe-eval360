<?php
/**
 * POST api/share.php — the demo link.
 *
 * Step two of the sales process is "we will create a system demo, and send you
 * a link to review it". This endpoint is that link: it saves a reviewed set-up
 * and serves it back to whoever opens the URL.
 *
 * Body (JSON), one of:
 *   {"action":"create","lead":{name,email,org,role},"payload":{…}}   → {slug, edit_token, url}
 *   {"action":"open","slug":"…","pass":"…"|null,"edit_token":"…"|null}
 *        → {ok:true, gate:true, org_name, …}  a contact is needed first
 *        → {ok:true, payload, role, meta}
 *   {"action":"unlock","slug":"…","lead":{…}}                        → {ok:true, payload, pass, …}
 *   {"action":"save","slug":"…","edit_token":"…","payload":{…}}      → {ok:true, revision}
 *   {"action":"fork","slug":"…","pass":"…","payload":{…}}            → {slug, edit_token, url}
 *   {"action":"event","slug":"…","kind":"meeting|exported|edited"}   → {ok:true}
 *
 * No AI call is made here, so this endpoint does not consume the AI quota; it
 * has its own per-visitor daily cap on creating demos.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib_share.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lf_fail('POST only.', 405, 'method');

// Same-origin only: the page that calls this is served from this site.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    lf_fail('Cross-site requests are not accepted.', 403, 'origin');
}

$cfg = lf_share_config();
if (!$cfg['enabled']) lf_fail('Sharing a demo is switched off on this server.', 503, 'share_off');

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) lf_fail('The request arrived empty — the set-up may be larger than the server accepts.', 413, 'empty');
$in = json_decode($raw, true);
if (!is_array($in)) lf_fail('Malformed request.', 400, 'json');

$action = (string) ($in['action'] ?? '');
$slug   = (string) ($in['slug'] ?? '');

/* A contact is only worth capturing if it can be replied to. */
function lf_share_read_lead(array $in): array
{
    $lead = is_array($in['lead'] ?? null) ? $in['lead'] : [];
    $name = trim((string) ($lead['name'] ?? ''));
    $email = trim((string) ($lead['email'] ?? ''));
    if (mb_strlen($name) < 2) lf_fail('Please give your name.', 422, 'name');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) lf_fail('Please give a valid email address, so we can reply to you.', 422, 'email');
    return ['name' => $name, 'email' => $email, 'org' => trim((string) ($lead['org'] ?? '')), 'role' => trim((string) ($lead['role'] ?? ''))];
}
function lf_share_payload_in(array $in): array
{
    $p = $in['payload'] ?? null;
    if (!is_array($p) || !is_array($p['org'] ?? null) || !is_array($p['programmes'] ?? null) || !count($p['programmes'])) {
        lf_fail('There is no set-up to share yet. Set up your organisation first.', 422, 'no_payload');
    }
    return $p;
}
/** What the browser is told about a demo. Never the edit token of a demo it does not own. */
function lf_share_meta(array $d, string $role): array
{
    return [
        'slug' => $d['slug'] ?? '', 'role' => $role, 'org_name' => $d['org_name'] ?? '', 'programmes' => (int) ($d['programmes'] ?? 0),
        'revision' => (int) ($d['revision'] ?? 1), 'created_at' => $d['created_at'] ?? null, 'updated_at' => $d['updated_at'] ?? null,
        'expires_at' => $d['expires_at'] ?? null, 'opens' => (int) ($d['opens'] ?? 0), 'is_copy' => !empty($d['parent']),
    ];
}

switch ($action) {

/* ── Create: the visitor turns their reviewed set-up into a link ─────────── */
case 'create':
    $payload = lf_share_payload_in($in);
    $lead = lf_share_read_lead($in);
    if (!lf_share_rate_ok()) lf_fail('You have made the most demo links we allow in one day. Email ' . $cfg['contact_email'] . ' and we will set one up for you.', 429, 'rate');
    $demo = lf_share_create($payload, $lead);
    lf_share_event($demo['slug'], 'created', $lead['org'], 1);
    $url = lf_share_public_url($demo['slug']);
    lf_share_notify('New demo: ' . ($lead['org'] !== '' ? $lead['org'] : $lead['name']), [
        'Someone built a demo and asked for a link.', '',
        'Name:         ' . $lead['name'],
        'Email:        ' . $lead['email'],
        'Organisation: ' . ($lead['org'] !== '' ? $lead['org'] : '—'),
        'Role:         ' . ($lead['role'] !== '' ? $lead['role'] : '—'),
        'Programmes:   ' . count($payload['programmes']),
        '', 'Review it: ' . $url,
        '', 'Next step: a discovery meeting (step three).',
    ]);
    lf_json(['ok' => true, 'slug' => $demo['slug'], 'edit_token' => $demo['edit_token'], 'url' => $url, 'meta' => lf_share_meta($demo, 'owner')]);

/* ── Open: somebody follows the link ────────────────────────────────────── */
case 'open':
    $demo = lf_share_find($slug);
    if (!$demo) lf_fail('This demo link has expired or does not exist. Ask for a new one, or set up your own.', 404, 'not_found');

    $token = (string) ($in['edit_token'] ?? '');
    if ($token !== '' && hash_equals((string) $demo['edit_token'], $token)) {
        lf_share_event($slug, 'opened', 'owner');
        lf_json(['ok' => true, 'payload' => $demo['payload'], 'role' => 'owner', 'edit_token' => $demo['edit_token'], 'meta' => lf_share_meta($demo, 'owner')]);
    }

    $pass = (string) ($in['pass'] ?? '');
    if ($cfg['gate'] && !lf_share_pass_ok($slug, $pass)) {
        // Who is behind the link is worth knowing, so the contact form comes
        // first. The organisation's name is shown so the visitor can see the
        // link really is theirs before typing anything.
        lf_json(['ok' => true, 'gate' => true, 'slug' => $slug, 'org_name' => $demo['org_name'], 'programmes' => (int) $demo['programmes']]);
    }
    lf_share_event($slug, 'opened', 'viewer', lf_share_lead_id($pass));
    lf_json(['ok' => true, 'payload' => $demo['payload'], 'role' => 'viewer', 'meta' => lf_share_meta($demo, 'viewer')]);

/* ── Unlock: the viewer gives their contact, and the demo opens ──────────── */
case 'unlock':
    $demo = lf_share_find($slug);
    if (!$demo) lf_fail('This demo link has expired or does not exist.', 404, 'not_found');
    $lead = lf_share_read_lead($in);
    $leadId = lf_share_lead($slug, 'viewer', $lead);
    if ($leadId === null) lf_fail('This demo link has expired or does not exist.', 404, 'not_found');
    lf_share_event($slug, 'gated', $lead['org'], $leadId);
    lf_share_event($slug, 'opened', 'viewer', $leadId);
    lf_share_notify('Demo opened: ' . ($demo['org_name'] !== '' ? $demo['org_name'] : $slug), [
        $lead['name'] . ' opened the demo.', '',
        'Name:         ' . $lead['name'],
        'Email:        ' . $lead['email'],
        'Organisation: ' . ($lead['org'] !== '' ? $lead['org'] : '—'),
        'Role:         ' . ($lead['role'] !== '' ? $lead['role'] : '—'),
        'Demo:         ' . ($demo['org_name'] !== '' ? $demo['org_name'] : '—') . ' (' . $slug . ')',
        '', lf_share_public_url($slug),
    ]);
    lf_json(['ok' => true, 'payload' => $demo['payload'], 'role' => 'viewer',
             'pass' => lf_share_pass($slug, $leadId), 'meta' => lf_share_meta($demo, 'viewer')]);

/* ── Save: whoever holds the edit token revises the demo in place ────────── */
case 'save':
    $demo = lf_share_find($slug);
    if (!$demo) lf_fail('This demo link has expired or does not exist.', 404, 'not_found');
    $token = (string) ($in['edit_token'] ?? '');
    if ($token === '' || !hash_equals((string) $demo['edit_token'], $token)) lf_fail('This demo can only be changed from the link that created it.', 403, 'not_owner');
    $payload = lf_share_payload_in($in);
    $after = lf_share_update($slug, $payload);
    if (!$after) lf_fail('The demo could not be saved just now. Please try again.', 503, 'store');
    lf_share_event($slug, 'edited', 'revision ' . (int) $after['revision']);
    lf_json(['ok' => true, 'revision' => (int) $after['revision'], 'url' => lf_share_public_url($slug), 'meta' => lf_share_meta($after, 'owner')]);

/* ── Fork: a viewer keeps their own editable copy ────────────────────────── */
case 'fork':
    $parent = lf_share_find($slug);
    if (!$parent) lf_fail('The demo this copy came from has expired.', 404, 'not_found');
    $payload = lf_share_payload_in($in);
    if (!lf_share_rate_ok()) lf_fail('You have made the most demo links we allow in one day. Try again tomorrow.', 429, 'rate');
    $pass = (string) ($in['pass'] ?? '');
    $lead = is_array($in['lead'] ?? null) && ($in['lead']['email'] ?? '') !== '' ? lf_share_read_lead($in) : null;
    if ($lead === null) {
        // The viewer has already been through the gate, so their contact is on
        // the original demo; carry it over rather than asking twice.
        $known = lf_share_find_lead($parent, lf_share_lead_id($pass));
        if ($known) $lead = ['name' => $known['name'], 'email' => $known['email'], 'org' => $known['org'], 'role' => $known['role']];
    }
    $demo = lf_share_create($payload, $lead, $slug);
    lf_share_event($slug, 'forked', $demo['slug'], lf_share_lead_id($pass));
    lf_share_event($demo['slug'], 'created', 'copy of ' . $slug, $lead ? 1 : null);
    $url = lf_share_public_url($demo['slug']);
    lf_share_notify('Demo copy edited: ' . ($parent['org_name'] !== '' ? $parent['org_name'] : $slug), [
        'A viewer saved their own edited copy of a demo — a strong buying signal.', '',
        'Who:      ' . ($lead ? $lead['name'] . ' <' . $lead['email'] . '>' . ($lead['org'] !== '' ? ' · ' . $lead['org'] : '') : '—'),
        'Original: ' . ($parent['org_name'] !== '' ? $parent['org_name'] : '—') . ' (' . $slug . ')',
        'Copy:     ' . $url,
    ]);
    lf_json(['ok' => true, 'slug' => $demo['slug'], 'edit_token' => $demo['edit_token'], 'url' => $url, 'meta' => lf_share_meta($demo, 'owner')]);

/* ── Event: what the visitor did with the demo ───────────────────────────── */
case 'event':
    $demo = lf_share_find($slug);
    if (!$demo) lf_json(['ok' => true]);                 // a dead link is not an error worth showing anyone
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, ['meeting', 'exported', 'edited'], true)) lf_fail('Unknown event.', 400, 'kind');
    $pass = (string) ($in['pass'] ?? '');
    $leadId = lf_share_pass_ok($slug, $pass) ? lf_share_lead_id($pass) : null;
    lf_share_event($slug, $kind, mb_substr((string) ($in['detail'] ?? ''), 0, 200), $leadId);
    if ($kind === 'meeting') {
        $l = lf_share_find_lead($demo, $leadId) ?: lf_share_find_lead($demo, (int) ($demo['owner_lead'] ?? 0));
        lf_share_notify('Discovery meeting requested: ' . ($demo['org_name'] !== '' ? $demo['org_name'] : $slug), [
            'Someone clicked through to book a discovery meeting (step three).', '',
            'Who:  ' . ($l ? $l['name'] . ' <' . $l['email'] . '>' . ($l['org'] !== '' ? ' · ' . $l['org'] : '') : '—'),
            'Demo: ' . ($demo['org_name'] !== '' ? $demo['org_name'] : '—') . ' (' . $slug . ')',
            '', lf_share_public_url($slug),
        ]);
    }
    lf_json(['ok' => true]);

default:
    lf_fail('Unknown action.', 400, 'action');
}
