<?php
/**
 * GET api/pipeline.php?key=… — the demo pipeline, for Vantage only.
 *
 * Who built a demo, who opened it and when, who asked for a discovery meeting.
 * That is what turns step two ("we send you a link") into step three ("we have
 * a discovery meeting"), so it needs to be visible without reading JSON files
 * over SSH.
 *
 * Gated by SHARE_ADMIN_KEY in the key file. With no key set, the page does not
 * exist. Each row links to the demo with its edit token, so a demo can be
 * revised before the discovery meeting.
 *
 * It lists names, organisations and counts — never the stored framework, which
 * keeps the rule that document content is not displayed server-side.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib_share.php';

$cfg = lf_share_config();
$given = (string) ($_GET['key'] ?? '');

if (!$cfg['admin_key'] || strlen($cfg['admin_key']) < 16) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n\nTo use this page, set SHARE_ADMIN_KEY (16 characters or more) in the key file.\n");
}
// A wrong key is indistinguishable from no page at all, and slow to guess.
if (!hash_equals($cfg['admin_key'], $given)) {
    usleep(400000);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

/* Only Vantage knows when a contract is signed or a team is trained, so the
   later steps are set here. Post/redirect/get, so a refresh does not resend. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $slug = (string) ($_POST['slug'] ?? '');
    $stage = (int) ($_POST['stage'] ?? 0);
    if (lf_share_find($slug) && isset(LF_SHARE_STAGES[$stage])) {
        lf_share_stage($slug, $stage, true);          // forced: Vantage may move it back as well as on
        lf_share_event($slug, 'staged', LF_SHARE_STAGES[$stage]);
    }
    header('Location: ?key=' . urlencode($given) . '#d-' . $slug);
    exit;
}

$demos = lf_share_all(200);
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$when = function (?string $iso): string {
    if (!$iso) return '—';
    $secs = time() - (int) strtotime($iso);
    if ($secs < 3600) return max(1, intdiv($secs, 60)) . ' min ago';
    if ($secs < 86400) return intdiv($secs, 3600) . ' h ago';
    if ($secs < 2592000) return intdiv($secs, 86400) . ' d ago';
    return gmdate('j M Y', (int) strtotime($iso));
};
$evOf = fn(array $d, string $kind) => array_values(array_filter($d['events'] ?? [], fn($x) => ($x['kind'] ?? '') === $kind));

/* Contacts as CSV, for the follow-up list. */
if (($_GET['csv'] ?? '') === 'leads') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eval360-demo-contacts-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['first_seen_utc', 'how', 'name', 'email', 'organisation', 'role', 'demo_slug', 'demo_organisation'], ',', '"', '\\');
    foreach ($demos as $d) {
        foreach ($d['leads'] ?? [] as $l) {
            fputcsv($out, [$l['created_at'], $l['kind'] === 'owner' ? 'built the demo' : 'opened the link',
                $l['name'], $l['email'], $l['org'], $l['role'], $d['slug'], $d['org_name']], ',', '"', '\\');
        }
    }
    exit;
}

$totals = ['demos' => count($demos), 'leads' => 0, 'opens' => 0, 'forks' => 0, 'meetings' => 0, 'past3' => 0];
foreach ($demos as $d) {
    $totals['leads'] += count($d['leads'] ?? []);
    $totals['opens'] += (int) ($d['opens'] ?? 0);
    $totals['forks'] += count($evOf($d, 'forked'));
    $totals['meetings'] += count($evOf($d, 'meeting'));
    if ((int) ($d['stage'] ?? 2) >= 4) $totals['past3']++;
}

$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/pipeline.php'))), '/');
$demoUrl = fn(array $d) => $base . '/logframe_eval360_demo.html?demo=' . $d['slug'];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Demo pipeline · Eval360</title>
<style>
  :root{--ink:#0f1c2a;--mute:#5a6878;--line:#e2e8ee;--brand:#1b3f66;--ok:#0f7b52;--warn:#9a6a00;--bg:#f6f8fa}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:400 14px/1.6 Inter,system-ui,-apple-system,sans-serif;padding:22px}
  h1{font:700 22px/1.2 Georgia,serif;margin:0 0 2px}
  .sub{color:var(--mute);font-size:13px;margin-bottom:20px}
  .cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
  .c{background:#fff;border:1px solid var(--line);border-radius:10px;padding:13px 17px;min-width:118px}
  .c b{display:block;font:700 23px/1.1 Georgia,serif}
  .c span{font-size:11.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em}
  .d{background:#fff;border:1px solid var(--line);border-radius:10px;padding:15px 17px;margin-bottom:12px}
  .dh{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline}
  .dh b{font:700 16px/1.3 Georgia,serif}
  .meta{font-size:12.5px;color:var(--mute)}
  table{width:100%;border-collapse:collapse;margin-top:11px;font-size:13px}
  th,td{text-align:left;padding:5px 9px 5px 0;border-bottom:1px solid #f0f3f6;vertical-align:top}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mute);font-weight:600}
  .tag{display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:20px;background:#eef2f6;color:var(--brand)}
  .tag.hot{background:#e7f5ee;color:var(--ok)} .tag.warn{background:#fdf3e0;color:var(--warn)}
  a{color:var(--brand)} .r{text-align:right;white-space:nowrap}
  .ev{font-size:12.5px;color:var(--mute);margin-top:9px;line-height:1.8}
  .stageform select{font:inherit;font-size:12.5px;padding:4px 7px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--ink)}
  .stage{display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:20px;background:#eaf0f6;color:var(--brand);margin-left:4px}
  .empty{background:#fff;border:1px dashed var(--line);border-radius:10px;padding:34px;text-align:center;color:var(--mute)}
  .tools{margin-bottom:18px;font-size:13px}
  code{background:#eef2f6;padding:1px 5px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<h1>Demo pipeline</h1>
<div class="sub">Who built a demo, who opened it, and who asked for a meeting. Times are UTC.</div>

<div class="cards">
  <div class="c"><b><?= (int) $totals['demos'] ?></b><span>Demos</span></div>
  <div class="c"><b><?= (int) $totals['leads'] ?></b><span>Contacts</span></div>
  <div class="c"><b><?= (int) $totals['opens'] ?></b><span>Opens</span></div>
  <div class="c"><b><?= (int) $totals['forks'] ?></b><span>Edited copies</span></div>
  <div class="c"><b><?= (int) $totals['meetings'] ?></b><span>Meeting clicks</span></div>
  <div class="c"><b><?= (int) $totals['past3'] ?></b><span>Past the demo</span></div>
</div>

<div class="tools">
  <a href="?key=<?= urlencode($given) ?>&amp;csv=leads">Download contacts as CSV</a>
  &nbsp;·&nbsp; Demos expire after <?= (int) $cfg['ttl_days'] ?> days and are then deleted.
  <?php if (!$cfg['notify']): ?>&nbsp;·&nbsp; <span class="tag warn">No notifications</span> set <code>SHARE_NOTIFY_EMAIL</code> to be emailed on each new demo, open and meeting click.<?php endif; ?>
  <?php if (!$cfg['meeting_url']): ?>&nbsp;·&nbsp; <span class="tag warn">No booking link</span> set <code>SHARE_MEETING_URL</code> so the demo can offer a discovery meeting.<?php endif; ?>
</div>

<?php if (!$demos): ?>
  <div class="empty">No demos yet. The first one appears here as soon as a visitor asks for a link.</div>
<?php endif; ?>

<?php foreach ($demos as $d):
    $leads = $d['leads'] ?? [];
    $meetings = $evOf($d, 'meeting');
    $events = array_reverse($d['events'] ?? []);
    $labels = ['created' => 'built', 'opened' => 'opened', 'gated' => 'gave contact', 'edited' => 'revised',
               'forked' => 'saved own copy', 'meeting' => 'asked for a meeting', 'exported' => 'exported a report',
               'staged' => 'moved on'];
?>
<div class="d" id="d-<?= $e($d['slug']) ?>">
  <div class="dh">
    <div>
      <b><?= $e($d['org_name'] !== '' ? $d['org_name'] : 'Untitled demo') ?></b>
      <span class="stage">Step <?= (int) ($d['stage'] ?? 2) ?> · <?= $e(LF_SHARE_STAGES[(int) ($d['stage'] ?? 2)] ?? '') ?></span>
      <?php if ($meetings): ?> <span class="tag hot">Meeting requested</span><?php endif; ?>
      <?php if (!empty($d['parent'])): ?> <span class="tag">Edited copy</span><?php endif; ?>
      <div class="meta"><?= (int) $d['programmes'] ?> programme<?= (int) $d['programmes'] === 1 ? '' : 's' ?>
        · built <?= $e($when($d['created_at'])) ?>
        · <?= (int) $d['opens'] ?> open<?= (int) $d['opens'] === 1 ? '' : 's' ?><?= !empty($d['last_open_at']) ? ', last ' . $e($when($d['last_open_at'])) : '' ?>
        · revision <?= (int) $d['revision'] ?>
        · expires <?= $e(gmdate('j M Y', (int) strtotime((string) $d['expires_at']))) ?></div>
    </div>
    <div class="r meta">
      <form method="post" action="?key=<?= urlencode($given) ?>" class="stageform">
        <input type="hidden" name="slug" value="<?= $e($d['slug']) ?>">
        <select name="stage" onchange="this.form.submit()" aria-label="Stage for <?= $e($d['org_name']) ?>">
          <?php foreach (LF_SHARE_STAGES as $k => $label): ?>
            <option value="<?= (int) $k ?>"<?= (int) ($d['stage'] ?? 2) === (int) $k ? ' selected' : '' ?>><?= (int) $k ?> · <?= $e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Set</button></noscript>
      </form>
      <div style="margin-top:7px">
        <a href="<?= $e($demoUrl($d)) ?>" target="_blank" rel="noopener">Open</a> &nbsp;
        <a href="<?= $e($demoUrl($d)) ?>&amp;t=<?= $e($d['edit_token']) ?>" target="_blank" rel="noopener"><b>Open to revise</b></a>
      </div>
    </div>
  </div>

  <?php if ($leads): ?>
  <table>
    <thead><tr><th>Contact</th><th>Email</th><th>Organisation</th><th>Role</th><th>How</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td><?= $e($l['name']) ?><?= (int) $l['id'] === (int) ($d['owner_lead'] ?? 0) ? ' <span class="tag">built it</span>' : '' ?></td>
        <td><a href="mailto:<?= $e($l['email']) ?>"><?= $e($l['email']) ?></a></td>
        <td><?= $e($l['org'] !== '' ? $l['org'] : '—') ?></td>
        <td><?= $e($l['role'] !== '' ? $l['role'] : '—') ?></td>
        <td><?= $e($l['kind'] === 'owner' ? 'Built the demo' : 'Opened the link') ?></td>
        <td><?= $e($when($l['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <div class="ev">No contact captured for this demo.</div>
  <?php endif; ?>

  <?php if ($events): ?>
  <div class="ev"><?php
    $parts = [];
    foreach (array_slice($events, 0, 14) as $x) $parts[] = $e(($labels[$x['kind']] ?? $x['kind']) . ' ' . $when($x['at'] ?? null));
    echo implode(' &nbsp;·&nbsp; ', $parts);
  ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="sub" style="margin-top:22px">
  Contacts are what visitors typed. Opens count page loads of the link, not unique people.
  Nothing from the stored framework is shown here — open a demo to see it.
</div>
</body>
</html>
