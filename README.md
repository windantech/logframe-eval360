# Eval360 · Your organisation, set up by AI

A public Eval360 demonstration that sets itself up for **your programmes**. Two ways in:

- **Start from your logframe(s)** — upload one or several (PDF, Word, Excel, CSV or text), or paste the
  text straight from Word or Excel. Each becomes a programme. No organisation name or website is needed; the name is taken from the logframe.
- **Start from your organisation** — enter its name and website: AI reads the website and builds the
  profile, strategic goals and a list of programmes. For each programme, upload its logframe or let AI
  draft a realistic one.

Strategic goals are optional and can be added later.

Once set up, two AI features run on the organisation's own data through OpenAI:

- **Ask Eval360** — ask questions in plain language ("Which programmes are off track, and why?") for the whole
  organisation or one programme. Answers cite indicators and figures from the data only.
- **AI report writer** — board summary, quarterly progress, donor report, performance analysis or data-quality
  brief, for a chosen audience and length; print or save as PDF, download as Word, or copy. Review everything, then Eval360 runs on it, in your brand colours: executive dashboard
across programmes, strategic goals, portfolio, a dashboard, logframe matrix, results chain and indicators
per programme, a verification workflow, framework quality, an audit trail and printable reports.

- **Website facts** keep a link to the page they came from.
- **Uploaded logframes** are read as written — the AI keeps your wording and never invents baselines or targets.
- **AI-drafted** goals, programmes and logframes are labelled as proposals everywhere.
- Nothing goes live until **you review** the extraction.
- **Progress, budget and reach figures are illustrative**, generated to show how Eval360 tracks results, and labelled.
- Website pages and documents go to OpenAI through the server and are **not stored**; the set-up stays in your browser —
  until you ask for a **demo link**, which saves the reviewed set-up (not your documents) so the link can open it.

## Sending the demo to someone

**Share this demo** turns the set-up into an unguessable link. Whoever opens it gives their name and email
first, then sees the whole demo and can change anything — targets, tolerance, branding. Their changes stay
in their browser until they **save their own copy**, which gives them a link of their own and leaves yours
as it was. You can revise your link in place at any time, and **How we work** lays out the seven steps from
first document to a system your team has adopted.

There is no account to make. We email you your link when you create it: one that opens the demo with you
still in control on any device, and one to pass on. Keep the first, send the second.

What a link stores: the reviewed framework — results, indicators, baselines and targets as you accepted them —
and nothing else. Uploaded PDFs, Word and Excel files are still never saved. Links are deleted after 90 days.

Without AI configured (or opened from disk) the demo still works with the built-in sample organisation.

## Run the tests

```bash
bash scripts/selftest.sh
bash scripts/e2e.sh        # full set-up through PHP + a local fixture website; no API key (mock mode)
```

## Deploy

See **[docs/DEPLOY.md](docs/DEPLOY.md)** — including where the OpenAI key goes (outside the web root).
Rules and design decisions: **[CLAUDE.md](CLAUDE.md)**.
