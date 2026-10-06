# Eval360 · Bring your own logframe · CLAUDE.md

Last updated: 2026-10-06 (build 1.0)

A public demonstration in which **any visitor imports their own logframe**. AI (OpenAI, through a
server-side proxy) reads it into a structured results framework; the visitor reviews and corrects it;
Eval360 then runs on it — dashboard, logframe matrix, results chain, indicators, verification
workflow, data quality, audit trail, reports.

Same single-file front-end conventions as the client demos (`../caaz-eval360`, `../kdc-eval360`);
this is the first with a server side.

---

## Layout

```
logframe_eval360_demo.html   the app — single file, no build step
index.html                   landing page
api/lib.php                  env, quotas, usage log, the JSON schema, the OpenAI call, the parser
api/extract.php              POST — document in, framework out
api/status.php               GET  — is AI on, imports left today (never the key)
samples/                     sample logframe as CSV, DOCX, PDF — for visitors to try the import
tests/e2e.js                 end-to-end harness, injected only by scripts/e2e.sh — never shipped
scripts/                     selftest.sh, e2e.sh, deploy_pull.sh
storage/                     created at runtime: rate limits, usage log — gitignored, web-blocked
```

## Three kinds of data — never mixed silently

| Provenance | What | Badge |
|---|---|---|
| Imported | Result statements, indicator wording, baselines, targets, means of verification, assumptions — the visitor's own | `impBadge()` |
| AI-extracted, reviewed | How each item got in; edits made on review are marked per indicator (`prov: imported / edited / added`) | `aiBadge()` |
| Illustrative | Every quarterly result, status and trend | `demoBadge()` |

- **The AI never invents numbers.** The schema makes baseline and target nullable and the
  instructions say a missing value is null and flagged. A missing target means "Target needed",
  not a guessed one.
- **Nothing goes live before review.** An extraction sits in `appData.draft` until the visitor accepts it.
- **Illustrative progress is seeded** by framework title + indicator, so the same logframe always
  gives the same figures. Performance bands are assigned by hash rank (about 60% on track / 25%
  needs attention / 15% off track) so every logframe shows a realistic mix.
- A missing baseline is assumed only for illustration (0 for counts, half the target for %, 1.6 ×
  target for decreasing indicators) and flagged wherever it shows.
- If the programme has completed fewer than three quarters (or starts in the future), tracking uses
  the last six quarters and says so.

## Server rules

- **The key never reaches the browser, a log or an error message.** It is read from
  `<domain>/private/logframe.env` (outside the web root; preferred), then `.env` in the project
  root (blocked by `.htaccess`), or the path in `LOGFRAME_ENV_FILE`.
- OpenAI **Responses API** with `text.format = json_schema, strict: true` and `store: false`.
  Strict mode requires every property to be required and `additionalProperties: false` at every
  level — `lf_schema()` keeps to that; the e2e/mock path checks the mock against it.
- Word and Excel are converted **in the browser** (mammoth, SheetJS, lazy-loaded from cdnjs): the
  host has no ZipArchive. PDFs go to OpenAI as `input_file`. Old `.doc` is refused with advice.
- Daily caps per visitor (salted IP hash) and in total; same-origin check on POST; input size limits.
- `AI_MOCK=1` returns a fixed extraction without calling OpenAI — for local testing.
- Usage log has tokens and timings only, never content.

## Front-end rules (as the other demos)

- Fragments are assembled into `logframe_eval360_demo.html`; edit that file directly now.
- `esc()` every value from a document — it is untrusted input. The self-test renders every screen
  (including review) with markup in the titles, statements, codes and notes and fails on any unescaped tag.
- No `</script>` inside the inline script (write `<\/script>`): it ends the block.
- The page contains `</body>` inside a JS string (the report template); anything that injects
  before `</body>` must use the **last** occurrence.
- State persists in `localStorage` only (`eval360.logframe.v1`), wrapped in try/catch; "Start over" clears it.
- Reports open via Blob URL with a Print / Save as PDF button.

```bash
bash scripts/selftest.sh   # model checks + every screen against the sample and awkward extractions
bash scripts/e2e.sh        # Word/Excel/PDF/CSV → PHP proxy (mock) → review → accept → storage
```

## Working agreement

1. Both tests pass before every commit.
2. Changing the schema: update `lf_schema()`, `lf_instructions()`, `lf_mock_extract()`,
   `SAMPLE_FRAMEWORK` and `normaliseFramework()` together.
3. Never log or display document content server-side.
