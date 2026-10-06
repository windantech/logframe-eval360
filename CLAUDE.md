# Eval360 · Your organisation, set up by AI · CLAUDE.md

Last updated: 2026-10-06 (build 2.0 — organisations, multiple programmes, website reading, branding)

A public demonstration that **sets itself up for the visitor's programmes**. Two entry points: (A) upload or paste
one or more logframes — no organisation name or website required (`minimalOrg`, name taken from the
logframe; strategic goals optional, addable later on the strategy screen); (B) give a name and website; the server reads the website (homepage + up to five relevant pages); AI builds the
organisation profile, strategic goals and programmes. Each programme gets its framework from an uploaded
logframe (extracted) or an AI draft (proposal). The visitor reviews everything, then Eval360 runs on it in
the organisation's colours: executive dashboard, profile, strategic goals, portfolio; per programme a
dashboard, logframe matrix, results chain and indicators; verification, framework quality, audit trail,
reports. Built in the visual style of the client demos (FPC, CIC, KDC, CAAZ).

Same single-file front-end conventions as the client demos (`../caaz-eval360`, `../kdc-eval360`);
this is the first with a server side.

---

## Layout

```
logframe_eval360_demo.html   the app — single file, no build step
index.html                   landing page
api/lib.php                  env, quotas, usage log, logframe schema, the OpenAI client, the parser
api/lib_web.php              safe website reader (SSRF-guarded), page text, brand hints
api/lib_org.php              organisation-profile schema, drafting prompt, mocks
api/extract.php              POST — logframe document in, framework out
api/profile.php              POST — name + website (+ description) in, profile + pages + brand out
api/draft.php                POST — organisation + programme in, drafted framework out
api/lib_ai.php               Ask Eval360 and report-writer prompts, report schema, mocks, context limits
api/ask.php                  POST — question + history + organisation data in, markdown answer out
api/report.php               POST — report type, audience, length + organisation data in, structured report out
api/status.php               GET  — is AI on, requests left today (never the key)
samples/                     sample logframe as CSV, DOCX, PDF — for visitors to try an upload
tests/e2e.js                 end-to-end harness, injected only by scripts/e2e.sh — never shipped
tests/fixture_site/          a fictional NGO website the e2e test reads
scripts/                     selftest.sh, e2e.sh, deploy_pull.sh
storage/                     created at runtime: rate limits, usage log — gitignored, web-blocked
```

## Kinds of data — never mixed silently

| Provenance | What | Badge |
|---|---|---|
| From the website | Profile, mission, facts, named programmes — each fact links to the page it came from | `webBadge()` |
| From the logframe | Uploaded framework: statements, indicators, baselines, targets — the organisation's own wording | `impBadge()` |
| AI-drafted proposal | Goals, programmes or whole frameworks the AI proposed because none was found | `draftBadge()` |
| Reviewed | Everything the AI read or proposed passed the review screen; edits are marked per indicator | `aiBadge()` |
| Illustrative | Every quarterly result, status, trend, budget and reach figure | `demoBadge()` |

- A fact may only cite a page that was actually read: `profile.php` nulls any other URL, and the
  browser keeps only `http(s)` URLs (`safeUrl`, `safeImg`, `hexOk` at ingestion).

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

## Data model

`org` → `goals` → `programmes[]` (each with `fw` = nodes + indicators, `source`, `goalCodes`, colour,
illustrative `budget`) → `results`. Node and indicator ids carry their programme (`P2-I7`), so one
flat lookup (`nodeById`, `indById`) works everywhere. During set-up, frameworks use temporary ids
(`X1-…`) and are re-keyed on accept (`rekeyFramework`). Strategic goals roll up from goal- and
outcome-level indicators of the programmes linked to them.

## Server rules

- **The key never reaches the browser, a log or an error message.** It is read from
  `<domain>/private/logframe.env` (outside the web root; preferred), then `.env` in the project
  root (blocked by `.htaccess`), or the path in `LOGFRAME_ENV_FILE`.
- OpenAI **Responses API** with `text.format = json_schema, strict: true` and `store: false`.
  Strict mode requires every property to be required and `additionalProperties: false` at every
  level — `lf_schema()` keeps to that; the e2e/mock path checks the mock against it.
- Word and Excel are converted **in the browser** (mammoth, SheetJS, lazy-loaded from cdnjs): the
  host has no ZipArchive. PDFs go to OpenAI as `input_file`. Old `.doc` is refused with advice.
- Daily caps per visitor (salted IP hash) and in total — every AI call counts once (a profile, each
  extraction, each draft); same-origin check on POST; input size limits.
- **Website reading is SSRF-guarded** (`lib_web.php`): http/https on ports 80/443 only; every resolved
  address must be public; the connection is pinned to the vetted address (`CURLOPT_RESOLVE`);
  redirects are followed by hand and re-vetted; 1.5 MB, 12 s and HTML-only caps. `AI_ALLOW_PRIVATE_FETCH=1`
  exists for the local e2e test only and must never be set on a server.
- `AI_MOCK=1` returns a fixed extraction without calling OpenAI — for local testing.
- Usage log has tokens and timings only, never content.

## Ask Eval360 and the AI report writer

- The browser builds the data the AI sees with `aiContext(scope)`: organisation, goals, and per programme the
  indicators (baseline, target, latest verified, expected, status, verification), gaps, risks, illustrative
  budget and reach, a six-quarter trend. Nothing else. Scope = whole organisation or one programme.
- Server prompts (`lib_ai.php`) require answers from that data only, never invented figures, and treat the
  data (wrapped in `<eval360_data>`) strictly as data. Illustrative and AI-drafted content is named as such.
- Answers are markdown rendered by `mdToHtml()`: escape first, then structure only (headings, bold, lists,
  tables) — no links, no raw HTML. Reports are a strict JSON schema rendered with `esc()` everywhere.
- Conversation (last 40 messages) and reports (last 12) persist in the browser; a new organisation starts fresh.

## Front-end rules (as the other demos)

- Fragments are assembled into `logframe_eval360_demo.html`; edit that file directly now.
- `esc()` every value from a document — it is untrusted input. The self-test renders every screen
  (including review) with markup in the titles, statements, codes and notes and fails on any unescaped tag.
- No `</script>` inside the inline script (write `<\/script>`): it ends the block.
- The page contains `</body>` inside a JS string (the report template); anything that injects
  before `</body>` must use the **last** occurrence.
- State persists in `localStorage` only (`eval360.org.v2`), wrapped in try/catch, never with file
  contents; "Start over" clears it. First visit opens the set-up wizard with the sample loaded behind it.
- Branding: `brandTokens()` derives brand / deep / soft from one colour and darkens colours too light to read.
- Reports open via Blob URL with a Print / Save as PDF button.

```bash
bash scripts/selftest.sh   # model checks + every screen against the sample and awkward extractions
bash scripts/e2e.sh        # website → profile → upload + draft → review → accept → add programme (mock)
```

## Working agreement

1. Both tests pass before every commit.
2. Changing a schema: update the schema, its instructions, its mock, the sample (`SAMPLE_PROFILE`,
   `SAMPLE_FRAMEWORKS`) and the normaliser (`normaliseOrg`, `normaliseFramework`) together.
3. Never log or display document content server-side.
