# Eval360 · Your organisation, set up by AI — deployment

Proposed address: **https://mel.eval360.tech/logframe/**, alongside `/fpc`, `/cic`, `/kdc` and `/caaz`.

Unlike the other demos this one has a server side: small PHP files in `api/` that hold the OpenAI key,
read organisations' public websites and call OpenAI. Requirements: PHP 8.1+ with `curl`, `json` and
`mbstring` (no Composer, no ZipArchive needed), Apache or LiteSpeed (for `.htaccess`), and **outbound
HTTPS** from the server — to api.openai.com and to the websites visitors enter.

## 1 · Push to GitHub

Create `windantech/logframe-eval360` on GitHub, then:

```bash
cd ~/logframe-eval360
git add -A && git commit -m "Eval360 bring-your-own-logframe demo"
git remote add origin https://github.com/windantech/logframe-eval360.git
git push -u origin main
```

## 2 · First deployment on the server

```bash
cd /home/vantage/domains/eval360.tech/public_html/mel
git clone https://github.com/windantech/logframe-eval360.git logframe
cd logframe
bash scripts/deploy_pull.sh
```

## 3 · Add the OpenAI key — outside the web root

The key file goes **outside `public_html`**, so the web server can never serve it, whatever happens
to `.htaccess`. `api/lib.php` looks for it at `<domain>/private/logframe.env`:

```bash
mkdir -p /home/vantage/domains/eval360.tech/private
cp /home/vantage/domains/eval360.tech/public_html/mel/logframe/.env.example \
   /home/vantage/domains/eval360.tech/private/logframe.env
nano /home/vantage/domains/eval360.tech/private/logframe.env     # paste the key, set APP_SALT
chmod 600 /home/vantage/domains/eval360.tech/private/logframe.env
```

Only `OPENAI_API_KEY` is required. The rest have safe defaults:

| Setting | Default | What it does |
|---|---|---|
| `OPENAI_MODEL` | `gpt-4.1-mini` | Extraction model. Must support PDF input and structured outputs. |
| `AI_LIMIT_PER_IP_DAY` | 40 | AI requests per visitor per day — a set-up with three programmes uses four; each Ask question and each AI report uses one |
| `AI_LIMIT_TOTAL_DAY` | 500 | AI requests for the whole site per day — the cost ceiling |
| `AI_MAX_PDF_MB` | 8 | Largest PDF accepted |
| `AI_MAX_TEXT_CHARS` | 150000 | Longest text accepted (about 40 pages) |
| `APP_SALT` | — | Any random string; visitor IPs are counted by salted hash, never stored |

Changes take effect immediately — no restart.

**Also set a monthly spending limit in the OpenAI dashboard** (Settings → Limits). The daily caps
protect against abuse; the OpenAI limit is the hard stop.

## 3b · Demo links — step two of the sales process

Sharing needs nothing installed: demos are JSON files under `storage/share/`, written with `flock`. No
database, no credentials, **no change to any connection string or env file on deploy**. Confirm the
directory is writable by the web user (it is created on first use, alongside `storage/ratelimit`).

Everything here is optional — a link works with none of it set — but two settings are worth having:

| Setting | What it does |
|---|---|
| `SHARE_NOTIFY_EMAIL` | Emailed on each new demo, each first open and each meeting click. Without it nothing is sent, and `api/pipeline.php` is the only way to see activity. |
| `SHARE_NOTIFY_FROM` | The From: address on mail to visitors — **set this one properly** (see below). |
| `SHARE_ADMIN_KEY` | 16+ characters. Opens `api/pipeline.php?key=…`: who built a demo, who opened it, who asked for a meeting, contacts as CSV. Unset, that page is a 404. |
| `SHARE_MEETING_URL` | The booking page for the discovery meeting. Without it the demo shows the email and phone instead. |
| `SHARE_TTL_DAYS` | How long a link lasts before it is **deleted** (default 90). |
| `SHARE_LIMIT_PER_IP_DAY` | Links one visitor may create a day (default 10). No AI call is made, so this is its own cap. |

```bash
nano /home/vantage/domains/eval360.tech/private/logframe.env
# SHARE_NOTIFY_EMAIL=eval360@vantageafricaleaders.com
# SHARE_ADMIN_KEY=<openssl rand -hex 24>
# SHARE_MEETING_URL=https://…
```

Generate the key with `openssl rand -hex 24`. Set a monthly spending limit in the OpenAI dashboard as
before — a demo link makes no AI call, but the visitor who opens it can use Ask Eval360 and the report
writer, and those do.

### Email — the same relay the rest of Eval360 uses

**This is load-bearing.** There is no login: when someone builds a demo we email them their own link, and
that email is the only copy of it outside the browser they made it in. If the mail does not arrive, they
lose their demo.

Mail therefore goes out through `mail.eval360.tech`, authenticated as `info@eval360.tech` — the same
mailbox the MEL system sends from — so that it genuinely originates from the domain and passes SPF and
DKIM. Put the same credentials in this project's key file:

```ini
SMTP_HOST=mail.eval360.tech
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=info@eval360.tech
SMTP_PASS=…          # the same password as the MEL system's _env
SMTP_FROM=info@eval360.tech
SMTP_FROM_NAME=Eval360
```

Copy the values across. **Do not point this project at the MEL `_env`:** that file also holds database
passwords, DPO payment tokens and Kobo keys, and a public demo has no business being able to read it.

Then send one and look — accepted is not the same as delivered:

```bash
php scripts/test_mail.php you@example.com
```

Replies go to `SHARE_CONTACT_EMAIL`, not the sending mailbox. Leave `SMTP_HOST` unset and mail falls back
to PHP's `mail()`, which sends as the web server's own address; expect the spam folder. Either way nothing
crashes: if the mail cannot be sent, the demo tells the visitor plainly that it could not email the link
and that they should copy it now.

**What a link stores.** The reviewed framework — results, indicators, baselines and targets as the visitor
accepted them — so the link can open it. Uploaded PDFs, Word and Excel files are still never saved.
Everyone who opens a link gives their name and email first (`SHARE_GATE=0` turns that off).
`SHARE_DISABLE=1` switches the whole feature off, and the demo hides it.

## 4 · Check it

```bash
# Must say "ai": true, and "share": {"enabled": true, …}
curl -s https://mel.eval360.tech/logframe/api/status.php

# Must all be 403 or 404 — never 200
for p in .env .env.example api/lib.php api/lib_web.php api/lib_org.php api/lib_ai.php api/lib_share.php \
         storage/ storage/share/demos/ tests/ .git/config; do
  echo "$p $(curl -s -o /dev/null -w '%{http_code}' https://mel.eval360.tech/logframe/$p)"; done

# The pipeline page: 404 without the key, 200 with it
curl -s -o /dev/null -w 'no key  %{http_code}\n' "https://mel.eval360.tech/logframe/api/pipeline.php?key=wrong"
curl -s -o /dev/null -w 'key     %{http_code}\n' "https://mel.eval360.tech/logframe/api/pipeline.php?key=$SHARE_ADMIN_KEY"
```

Then open the site and set up a real organisation: enter a name and its website, choose **Upload our
logframe** for one programme (try `samples/sample_logframe.pdf`) and **AI drafts one** for another.
Finally press **Share this demo**, open the link it gives you in a private window, and check the contact
form appears and the demo opens behind it. The demo should then show up in `api/pipeline.php?key=…`, where
you can also move it along the seven steps. Check the email arrived, and that the `&t=…` link in it opens
the demo with **Share this demo** in the top bar — that is the test that someone can come back to their
own demo on another machine.

If the PDF upload fails with "arrived empty", the host's `post_max_size` is below the PDF size — raise it
to 16M in the PHP settings for the domain, or lower `AI_MAX_PDF_MB`.

## Upgrading from build 1 (single logframe)

Nothing to change on the server beyond pulling: `bash scripts/deploy_pull.sh`. The key file stays where it is.
- The server now also makes outbound requests to the websites visitors enter — most shared hosts allow this.
- The default per-visitor limit is now 40 AI requests a day (a set-up uses one per programme plus one; each
  Ask question and AI report uses one); if your key file sets a lower `AI_LIMIT_PER_IP_DAY`, consider raising it.
- Visitors' browsers start fresh: build 1's saved logframe is not carried over.

## 5 · Every update

```bash
bash scripts/selftest.sh     # locally — model checks and every screen
bash scripts/e2e.sh          # locally — full set-up through PHP + fixture website, mock mode, no key needed
git add -A && git commit -m "…" && git push
```

On the server: `bash scripts/deploy_pull.sh`. The key file is untracked and never touched.

## Cost and usage

Each AI request is one OpenAI call: the website profile, one per programme, one per Ask Eval360
question, one per AI report. Ask and reports send the organisation's data (typically 5–25 KB) with every call. With `gpt-4.1-mini`
a full set-up with three programmes should cost a few cents; a long PDF a little more (estimates — the
usage log has the real token counts). Every call is logged — date, hashed visitor, model, tokens, no content — in
`storage/logs/usage-YYYY-MM.csv` (blocked from the web).

## Privacy, in one paragraph

Website pages and the visitor's documents are sent to OpenAI through this server and are not stored here.
If a visitor asks for a demo link, the set-up they reviewed is stored under `storage/share/` so the link can
open it, together with the name, email and organisation of everyone who opens that link; it is all deleted
when the link expires. The documents the set-up was extracted from are still never stored.
The website reader refuses private and internal addresses, so it cannot be used to probe the server's network. Requests are
sent with `store: false`. The set-up lives only in the visitor's browser. The set-up
screen tells visitors this, and not to upload documents they may not share with a third-party AI service.
