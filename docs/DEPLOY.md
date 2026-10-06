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
| `AI_LIMIT_PER_IP_DAY` | 25 | AI requests per visitor per day — a set-up with three programmes uses four |
| `AI_LIMIT_TOTAL_DAY` | 300 | AI requests for the whole site per day — the cost ceiling |
| `AI_MAX_PDF_MB` | 8 | Largest PDF accepted |
| `AI_MAX_TEXT_CHARS` | 150000 | Longest text accepted (about 40 pages) |
| `APP_SALT` | — | Any random string; visitor IPs are counted by salted hash, never stored |

Changes take effect immediately — no restart.

**Also set a monthly spending limit in the OpenAI dashboard** (Settings → Limits). The daily caps
protect against abuse; the OpenAI limit is the hard stop.

## 4 · Check it

```bash
# Must say "ai": true
curl -s https://mel.eval360.tech/logframe/api/status.php

# Must all be 403 or 404 — never 200
for p in .env .env.example api/lib.php api/lib_web.php api/lib_org.php storage/ tests/ .git/config; do
  echo "$p $(curl -s -o /dev/null -w '%{http_code}' https://mel.eval360.tech/logframe/$p)"; done
```

Then open the site and set up a real organisation: enter a name and its website, choose **Upload our
logframe** for one programme (try `samples/sample_logframe.pdf`) and **AI drafts one** for another.

If the PDF upload fails with "arrived empty", the host's `post_max_size` is below the PDF size — raise it
to 16M in the PHP settings for the domain, or lower `AI_MAX_PDF_MB`.

## Upgrading from build 1 (single logframe)

Nothing to change on the server beyond pulling: `bash scripts/deploy_pull.sh`. The key file stays where it is.
- The server now also makes outbound requests to the websites visitors enter — most shared hosts allow this.
- The default per-visitor limit is now 25 AI requests a day (a set-up uses one per programme plus one);
  if your key file sets `AI_LIMIT_PER_IP_DAY=10`, raise it.
- Visitors' browsers start fresh: build 1's saved logframe is not carried over.

## 5 · Every update

```bash
bash scripts/selftest.sh     # locally — model checks and every screen
bash scripts/e2e.sh          # locally — full set-up through PHP + fixture website, mock mode, no key needed
git add -A && git commit -m "…" && git push
```

On the server: `bash scripts/deploy_pull.sh`. The key file is untracked and never touched.

## Cost and usage

Each AI request is one OpenAI call: the website profile, then one per programme. With `gpt-4.1-mini`
a full set-up with three programmes should cost a few cents; a long PDF a little more (estimates — the
usage log has the real token counts). Every call is logged — date, hashed visitor, model, tokens, no content — in
`storage/logs/usage-YYYY-MM.csv` (blocked from the web).

## Privacy, in one paragraph

Website pages and the visitor's documents are sent to OpenAI through this server and are not stored here.
The website reader refuses private and internal addresses, so it cannot be used to probe the server's network. Requests are
sent with `store: false`. The set-up lives only in the visitor's browser. The set-up
screen tells visitors this, and not to upload documents they may not share with a third-party AI service.
