# Eval360 · Bring your own logframe — deployment

Proposed address: **https://mel.eval360.tech/logframe/**, alongside `/fpc`, `/cic`, `/kdc` and `/caaz`.

Unlike the other demos this one has a server side: two small PHP files in `api/` that hold the
OpenAI key and call OpenAI. Requirements: PHP 8.1+ with `curl` and `json` (no Composer, no
ZipArchive needed), Apache or LiteSpeed (for `.htaccess`).

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
| `AI_LIMIT_PER_IP_DAY` | 10 | Imports per visitor per day |
| `AI_LIMIT_TOTAL_DAY` | 300 | Imports for the whole site per day — the cost ceiling |
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
for p in .env .env.example api/lib.php storage/ .git/config; do
  echo "$p $(curl -s -o /dev/null -w '%{http_code}' https://mel.eval360.tech/logframe/$p)"; done
```

Then open the site, choose **Import your logframe**, and upload `samples/sample_logframe.pdf`.

If the PDF upload fails with "arrived empty", the host's `post_max_size` is below the PDF size — raise it
to 16M in the PHP settings for the domain, or lower `AI_MAX_PDF_MB`.

## 5 · Every update

```bash
bash scripts/selftest.sh     # locally — model checks and every screen
bash scripts/e2e.sh          # locally — full import path through PHP, mock mode, no key needed
git add -A && git commit -m "…" && git push
```

On the server: `bash scripts/deploy_pull.sh`. The key file is untracked and never touched.

## Cost and usage

Each import is one OpenAI call. With `gpt-4.1-mini` a typical logframe costs around a cent;
a long PDF a few cents. Every call is logged — date, hashed visitor, model, tokens, no content — in
`storage/logs/usage-YYYY-MM.csv` (blocked from the web).

## Privacy, in one paragraph

The visitor's document is sent to OpenAI through this server and is not stored here. Requests are
sent with `store: false`. The extracted framework lives only in the visitor's browser. The import
screen tells visitors this, and not to upload documents they may not share with a third-party AI service.
