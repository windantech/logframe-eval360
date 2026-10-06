<?php
/**
 * Organisation profile (from the website or a description) and drafted
 * logframes for programmes that have none. Both are strict JSON schemas.
 *
 * Honesty rules, enforced in the prompt and labelled in the app:
 * - a fact must come from a page that was read, with that page's URL;
 * - anything the AI proposes (goals, programmes, every number in a drafted
 *   logframe) is marked as proposed — it is never presented as the organisation's own.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function lf_profile_schema(): array
{
    $str = ['type' => 'string'];
    $nstr = ['type' => ['string', 'null']];
    $arr = fn(array $items) => ['type' => 'array', 'items' => $items];
    $obj = fn(array $props) => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
    return $obj([
        'org' => $obj([
            'name' => $str, 'short_name' => $str, 'sector' => $str, 'country' => $nstr,
            'type' => ['type' => 'string', 'enum' => ['NGO', 'Government agency', 'Regulator', 'Development finance institution', 'Foundation',
                       'UN or multilateral agency', 'University or research', 'Private company', 'Network or association', 'Other']],
            'summary' => $str, 'mission' => $nstr, 'vision' => $nstr, 'values' => $arr($str), 'focus_areas' => $arr($str),
            'founded' => $nstr, 'headquarters' => $nstr]),
        'facts' => $arr($obj(['statement' => $str, 'source_url' => $nstr,
            'category' => ['type' => 'string', 'enum' => ['mandate', 'mission', 'vision', 'programme', 'reach', 'partnership', 'governance', 'history', 'other']]])),
        'strategic_goals' => $arr($obj(['code' => $str, 'statement' => $str, 'basis' => ['type' => 'string', 'enum' => ['stated', 'proposed']]])),
        'programmes' => $arr($obj(['name' => $str, 'short_name' => $str, 'description' => $str, 'location' => $nstr,
            'period_start' => $nstr, 'period_end' => $nstr, 'goal_codes' => $arr($str), 'target_group' => $nstr,
            'basis' => ['type' => 'string', 'enum' => ['stated', 'proposed']], 'source_url' => $nstr])),
        'knowledge_basis' => ['type' => 'string', 'enum' => ['website', 'description', 'general_knowledge', 'mixed']],
        'warnings' => $arr($str),
    ]);
}

function lf_profile_instructions(): string
{
    return <<<'TXT'
You build an organisation profile for Eval360, a monitoring, evaluation and performance platform, so that a
demonstration can be set up around the organisation's real work.

You receive the organisation's name, optionally pages read from its public website (each with its URL),
and optionally a description typed by the visitor.

Rules:
- facts: only statements supported by the website pages or the visitor's description. source_url must be
  exactly one of the page URLs given (or null for the visitor's description). Keep them short and specific:
  mandate, mission, reach figures, named programmes, partners, founding year. No marketing adjectives.
  Do not state figures that are not in the text.
- org.mission / org.vision / values: use the organisation's own wording when the pages state them; otherwise null / empty.
- strategic_goals: 3 to 5. If the pages state strategic objectives or pillars, use them (basis "stated");
  otherwise propose goals that fit the organisation's mandate (basis "proposed"). Codes SG1, SG2…
- programmes: 2 to 4. Prefer programmes, projects or initiatives named on the website (basis "stated",
  with source_url). If fewer than two are named, propose realistic ones that fit the mandate (basis "proposed").
  Each links to one or more strategic goal codes. Give a location and target group where the text supports it.
  Periods: use stated dates; otherwise null.
- If no website text was provided, you may use widely known public information about well-known organisations,
  but set knowledge_basis "general_knowledge" and add a warning that every item must be verified.
  For an organisation you do not recognise, rely only on the description and propose conservatively.
- warnings: plain language — what could not be read, what was proposed rather than found.
- Never include personal data about named individuals other than leadership roles stated on the site.
TXT;
}

function lf_draft_instructions(): string
{
    return <<<'TXT'
You draft a results framework (logframe) for one programme of an organisation, for an Eval360 demonstration.
The organisation has not supplied its own logframe, so this is a realistic PROPOSAL, clearly labelled as such.

Make it specific to the organisation, sector, country and programme described — the kind of logframe a
competent M&E specialist would write for this programme:
- goal (impact) with 1–2 indicators; 2–3 outcomes with 1–2 indicators each; 1–3 outputs per outcome with
  1–2 indicators each; 2–4 activities per output; assumptions at outcome level; 3–5 programme risks.
- SMART indicators with units. Plausible baselines and end-of-programme targets for the scale described;
  output baselines are usually 0. Use direction "decrease" where lower is better.
- period_start / period_end: the programme's dates if given, otherwise 2025-01-01 to 2027-12-31.
- For every indicator: source_quote "AI-drafted proposal", confidence "low", notes = one short sentence on
  why the target is plausible, means_of_verification and frequency realistic for the sector.
- is_logframe true; not_logframe_reason null; title = the programme name.
- warnings must include: "AI-drafted proposal based on public information — replace with the organisation's own logframe and targets."
TXT;
}

/* ── Mocks for local testing (AI_MOCK=1) ─────────────────────────────────── */
function lf_mock_profile(string $name, array $pages, string $description): array
{
    $u = $pages[0]['url'] ?? null;
    return [
        'org' => ['name' => $name, 'short_name' => strtoupper(substr(preg_replace('/[^A-Za-z ]/', '', $name), 0, 1)) . 'MO', 'sector' => 'Water, sanitation and livelihoods',
                  'country' => 'Mock country', 'type' => 'NGO', 'summary' => 'Mock profile: AI_MOCK is on, so no AI call was made. ' . ($pages ? count($pages) . ' website page(s) were read.' : 'No website was read.'),
                  'mission' => 'To improve lives in rural communities (mock).', 'vision' => null, 'values' => ['Integrity', 'Partnership'],
                  'focus_areas' => ['Water and sanitation', 'Livelihoods', 'Climate resilience'], 'founded' => null, 'headquarters' => null],
        'facts' => array_values(array_filter([
            $u ? ['statement' => 'Website title: ' . ($pages[0]['title'] ?? ''), 'source_url' => $u, 'category' => 'other'] : null,
            $description !== '' ? ['statement' => mb_substr($description, 0, 140), 'source_url' => null, 'category' => 'mission'] : null])),
        'strategic_goals' => [
            ['code' => 'SG1', 'statement' => 'Universal access to safe water and sanitation in target areas', 'basis' => 'proposed'],
            ['code' => 'SG2', 'statement' => 'Resilient rural livelihoods', 'basis' => 'proposed'],
            ['code' => 'SG3', 'statement' => 'Strong local institutions and accountability', 'basis' => 'proposed']],
        'programmes' => [
            ['name' => 'Rural Water Access Programme', 'short_name' => 'RWAP', 'description' => 'Boreholes and water committees (mock).', 'location' => 'Three districts',
             'period_start' => '2025-01-01', 'period_end' => '2027-12-31', 'goal_codes' => ['SG1', 'SG3'], 'target_group' => 'Rural households', 'basis' => 'proposed', 'source_url' => null],
            ['name' => 'Climate-Smart Livelihoods Initiative', 'short_name' => 'CSLI', 'description' => 'Farmer field schools and savings groups (mock).', 'location' => 'Two provinces',
             'period_start' => '2024-07-01', 'period_end' => '2027-06-30', 'goal_codes' => ['SG2'], 'target_group' => 'Smallholder farmers', 'basis' => 'proposed', 'source_url' => null]],
        'knowledge_basis' => $pages ? 'website' : 'description',
        'warnings' => ['Mock mode: no AI call was made.'],
    ];
}
function lf_mock_draft(string $programme): array
{
    $m = lf_mock_extract($programme);
    $m['title'] = $programme;
    $m['summary'] = 'Mock draft for ' . $programme . '.';
    foreach ($m['goal']['indicators'] as &$i) { $i['source_quote'] = 'AI-drafted proposal'; $i['confidence'] = 'low'; }
    unset($i);
    $m['warnings'] = ["AI-drafted proposal based on public information — replace with the organisation's own logframe and targets.", 'Mock mode: no AI call was made.'];
    return $m;
}
