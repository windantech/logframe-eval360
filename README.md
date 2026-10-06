# Eval360 · Bring your own logframe

A public Eval360 demonstration: upload your logical framework (PDF, Word, Excel, CSV or text),
AI reads it into a structured results framework, you review and correct it, and Eval360 runs on it —
dashboard, logframe matrix, results chain, indicator tracking, verification workflow, data quality
and reports.

- The **framework** is yours. The AI keeps your wording and never invents baselines or targets.
- Nothing goes live until **you review** the extraction.
- **Progress figures are illustrative**, generated to show how Eval360 tracks results, and labelled.
- Your document goes to OpenAI through the server and is **not stored**; the framework stays in your browser.

Without AI configured (or opened from disk) the demo still works with the built-in sample.

## Run the tests

```bash
bash scripts/selftest.sh
bash scripts/e2e.sh        # needs PHP and Chrome; no API key (mock mode)
```

## Deploy

See **[docs/DEPLOY.md](docs/DEPLOY.md)** — including where the OpenAI key goes (outside the web root).
Rules and design decisions: **[CLAUDE.md](CLAUDE.md)**.
