# Eval360 · Your organisation, set up by AI

A public Eval360 demonstration that sets itself up for **your programmes**. Two ways in:

- **Start from your logframe(s)** — upload one or several (PDF, Word, Excel, CSV or text). Each becomes a
  programme. No organisation name or website is needed; the name is taken from the logframe.
- **Start from your organisation** — enter its name and website: AI reads the website and builds the
  profile, strategic goals and a list of programmes. For each programme, upload its logframe or let AI
  draft a realistic one.

Strategic goals are optional and can be added later. Review everything, then Eval360 runs on it, in your brand colours: executive dashboard
across programmes, strategic goals, portfolio, a dashboard, logframe matrix, results chain and indicators
per programme, a verification workflow, framework quality, an audit trail and printable reports.

- **Website facts** keep a link to the page they came from.
- **Uploaded logframes** are read as written — the AI keeps your wording and never invents baselines or targets.
- **AI-drafted** goals, programmes and logframes are labelled as proposals everywhere.
- Nothing goes live until **you review** the extraction.
- **Progress, budget and reach figures are illustrative**, generated to show how Eval360 tracks results, and labelled.
- Website pages and documents go to OpenAI through the server and are **not stored**; the set-up stays in your browser.

Without AI configured (or opened from disk) the demo still works with the built-in sample organisation.

## Run the tests

```bash
bash scripts/selftest.sh
bash scripts/e2e.sh        # full set-up through PHP + a local fixture website; no API key (mock mode)
```

## Deploy

See **[docs/DEPLOY.md](docs/DEPLOY.md)** — including where the OpenAI key goes (outside the web root).
Rules and design decisions: **[CLAUDE.md](CLAUDE.md)**.
