# DOWScripts — Implementation Plan

> **SUPERSEDED — kept for history, not current guidance.** This was the original design
> doc, written before any of it was built. The app described below now exists — see
> `CLAUDE.md` for the rules that survived unchanged (wrapper-not-rewrite, safety model,
> registry-driven scripts), `../ARCHITECTURE.md` for the as-built layout, and
> `DEPLOYMENT.md` for how it actually runs in production. Specifics below (folder name,
> script count, which marketplaces have pipelines, path examples) are stale — the repo
> was later restructured (`dowscripts/` → `web/`, marketplace folders grouped under
> `marketplaces/`) and Amazon/Walmart pipelines were since built.

---

## 0. In plain English (read this first)

Everything in `shopify/`, `ebay/` (and eventually `walmart/`, `amazon/`) is a pile of CLI
PHP/Python scripts. They work, and several of them have been proven live in production —
but using them requires knowing PHP, knowing which script comes after which, knowing the
right flags, and being comfortable running things from a terminal. That's fine for a dev,
but the whole point of pulling this into its own repo was so **future non-dev teammates**
could make catalog changes without needing any of that.

**DOWScripts is a small internal Laravel web app that puts a UI in front of the existing
scripts.** A nav bar with one dropdown per marketplace, each script gets a human-readable
title/description/usage/type (read or write), read scripts hand back a CSV, write scripts
report success/failure with the same safety rails the CLI already has (dry-run first,
explicit confirmation before anything live). It does **not** reimplement any marketplace
logic — it only ever invokes the scripts that already exist and already work.

---

## 1. The one decision everything else follows from

**DOWScripts is a wrapper, not a rewrite.** It shells out to the existing, already
production-tested scripts as subprocesses rather than porting their logic into Laravel
natively. Those scripts encode real, hard-won correctness that cost real debugging time
to get right the first time:
- the **vary-by guard** (never rewrite a listing's variation-defining aspect value —
  orphans eBay sales history)
- the **MULTI-cardinality comma-split fix** (some allowed values themselves contain a
  comma, e.g. eBay's Theme picklist has `"Cartoon"` and `"Cartoon, TV & Movie
  Characters"` as two different single entries)
- the **SEOInput full-replace lesson** on the Shopify side (omitting a field nulls it,
  doesn't leave it alone)

Reimplementing any of this natively in Laravel would mean re-deriving all of it from
scratch with no track record. Wrapping means DOWScripts inherits correctness it didn't
have to earn.

**Practical implication:** DOWScripts contains almost no marketplace-specific logic of its
own. What it contains is: a registry describing each script, a UI to fill in its
parameters, a job runner that builds the right CLI invocation and executes it, and a
safety layer that mirrors the CLI's existing guardrails in web form.

---

## 2. Where it lives

New top-level folder in this same repo: **`dowscripts/`**, alongside `shopify/`, `ebay/`,
`walmart/`, `amazon/`. It gets its own `composer.json`/`vendor/` (standard for a Laravel
app) — this coexists fine with the root repo's own `composer.json` (which serves the
plain scripts) since they're in different directories with independent autoloading.
Living in the same repo (rather than a separate one) keeps one git history and means
`RunScriptJob` can reference sibling scripts with a simple relative path
(`../ebay/scripts/apply_aspects.php`) instead of a cross-repo dependency.

## 3. Deployment & access

Deployed on a small always-on shared server/VM reachable by the whole team over the
browser — running it only via `artisan serve` on one person's laptop would defeat the
actual goal (a non-dev being able to self-serve without a dev environment or someone else
babysitting a terminal). Since this app will hold live marketplace credentials and can
trigger real production writes, it gets minimal auth (Laravel's standard scaffolding —
Breeze or Fortify — with just 1-2 accounts, not a public sign-up flow).

---

## 4. Domain model (OOP core)

| Class | Kind | Responsibility |
|---|---|---|
| `ScriptDefinition` | value object | slug, marketplace, pipeline/category, title, description, usage notes, type (`Read`/`Write` enum), CLI path, list of `ParamDefinition`s |
| `ParamDefinition` | value object | name, CLI flag, type (string/enum/bool/int), required, default, options (for enums like `account: dows\|ige`), help text |
| `ScriptRegistry` | service | loads all `ScriptDefinition`s from config, exposes `all()`, `forMarketplace()`, `find(slug)` |
| `MarketplaceCredential` | Eloquent model | encrypted casts; one row per marketplace+account; holds whatever env vars that account's scripts need |
| `ScriptRun` | Eloquent model | execution history/audit log: script, params, user, status, exit code, stdout/stderr, output file paths, timestamps |
| `RunScriptJob` | queued job | given a `ScriptRun`, builds the CLI argv from its `ScriptDefinition` + submitted params, executes via an injected `ProcessRunner` (env pulled from `MarketplaceCredential`), captures output, updates the run row |
| `ProcessRunner` | interface | thin wrapper around Symfony `Process`; exists so tests can fake it entirely and never actually shell out |

**Registry design:** rather than one PHP class per script (89 of them and counting), scripts
are defined as **data** — a PHP config array per marketplace (e.g. `config/scripts/ebay.php`)
— validated into `ScriptDefinition` DTOs at boot by `ScriptRegistry`. Still fully typed/OOP
(value objects + a registry service), just without near-duplicate boilerplate for every
script. Adding a new script later = add a config entry + tests, not a new class or
controller.

---

## 5. The "script contract" gap (must fix before wrapping)

The existing scripts print output meant for a human reading a terminal, not a machine
parsing a response. To drive a UI reliably (and to unit-test the wrapper without scraping
printed text), the contract needs to be:
- **exit code is the authoritative pass/fail signal** (0 = success, non-zero = failure) —
  already true for most scripts, needs auditing across all of them
- **full stdout/stderr captured and shown** in the run detail page, not parsed for meaning
- **known output artifacts (CSVs, JSON) linked for download** — these paths are already
  documented in each script's own docblock, so the registry just needs to record them

**One concrete gap already known:** `apply_aspects.php`'s bulk mode currently returns exit
0 even if some individual items failed mid-run (it logs the failure to
`apply_aspects_run.csv` and continues to the next item) — a false "succeeded" in the UI.
Before wrapping it, this needs a small, scoped exit-code fix (0 = all succeeded, 1 = some
failed, 2 = hard error), not a rewrite of its logic. Expect a handful of similar small
audits/fixes across other write scripts during Phase 3 below — flag them as found, don't
try to pre-audit all 89 scripts up front.

---

## 6. Safety model (non-negotiable)

The CLI's existing guardrails — dry-run by default, `--verify` (server round-trip, no
commit), `--live` + retype-the-item-id, `--confirm=WRITE` for bulk — must have an exact
web equivalent, not a weaker approximation:
1. Any `Write`-type script's run page **always shows a dry-run/preview result first**;
   there is no path that skips straight to a live call.
2. A single-item live write requires the user to **retype the item ID** in a confirmation
   modal before it fires — mirrors the CLI exactly.
3. A bulk live write (more than one item) requires the user to **type the literal word
   "WRITE"** — mirrors `--confirm=WRITE` exactly.
4. Every confirmation (what was typed, by whom, when) is recorded on the `ScriptRun` row —
   this is the audit trail for "who ran the big write."

---

## 7. Pages / routes

- `/` — dashboard: recent runs, credential status per marketplace
- Nav bar: **Shopify / eBay / Walmart / Amazon**, each a dropdown of curated "common"
  scripts (a `featured: true` flag on the registry entry) + an **All scripts** link.
  Walmart/Amazon show an empty state ("pipeline not built yet") until those exist.
- `/scripts/{marketplace}` — full script index for that marketplace, grouped by
  pipeline/category, filterable by read/write
- `/scripts/{marketplace}/{slug}` — detail page: title, description, usage notes, and a
  form generated from its `ParamDefinition`s; submit runs it (through the safety flow in
  §6 if it's a write script)
- `/runs`, `/runs/{id}` — run history + per-run detail (params used, full output, file
  download links, status)
- `/credentials`, `/credentials/{marketplace}/{account}/edit` — per-account credential
  forms, backed by `MarketplaceCredential`

---

## 8. Testing strategy (strict TDD)

**Tests never shell out to a real script or hit a real marketplace API.** That correctness
already lives in, and stays owned by, the scripts themselves — re-testing it here would be
redundant at best and risky at worst (a test accidentally hitting production). Concretely:
- `ProcessRunner` is faked in every test; what gets asserted is that `RunScriptJob` built
  the **exact expected argv/env** for given form input — not that a real process ran.
- `ScriptRegistry` unit tests: config loads correctly, malformed entries fail loudly, DTOs
  validate.
- Feature tests per route: dashboard renders, nav dropdowns list the right scripts, a
  script's detail page renders the right dynamic form from its `ParamDefinition`s, run
  history renders.
- Write-confirmation flow: a write-type run **cannot** transition to executed without the
  exact matching confirmation text, and cannot skip the dry-run-first step — test both
  as explicit negative cases.
- Credentials: round-trip through encryption correctly; tests use fake tokens, never real
  secrets.

Red-green-refactor is expected practice, not just "add tests eventually" — this gets
written into `CONTRIBUTING.md` explicitly (§10).

---

## 9. Rough build order

0. Laravel scaffold + auth (Breeze) + nav shell + `CLAUDE.md` + `CONTRIBUTING.md` committed
1. `ScriptDefinition`/`ParamDefinition`/`ScriptRegistry`, piloted on eBay only, fully tested
2. `MarketplaceCredential` model + credentials UI
3. `ProcessRunner` + `RunScriptJob` + `ScriptRun`, proven end-to-end on one real **read**
   script (e.g. `audit_listings.php`) — includes auditing/fixing that script's exit codes
   per §5 if needed
4. Write-safety flow (§6) proven end-to-end on one real **write** script
5. Fill out the registry for the rest of eBay, then Shopify; Walmart/Amazon stay empty
   until those pipelines exist
6. Run history polish, then deploy to the shared server (§3)

---

## 10. `CLAUDE.md` / `CONTRIBUTING.md` — planned contents

Both get created in Phase 0, before any feature work. Outline to build from:

**`dowscripts/CLAUDE.md`**
- This app never reimplements marketplace logic — it only ever invokes the existing
  scripts' own paths, including their `--live` flag; no native eBay/Shopify API calls
  belong in this codebase.
- Carries forward the vary-by rule and the SEOInput-clobber history as context, even
  though this app doesn't touch that logic directly — anyone editing `RunScriptJob` or a
  script's `ParamDefinition`s needs to understand why those flags exist.
- TDD is mandatory, not optional — see §8 above.
- Registry-driven: adding a new script is a config entry + tests, not new controller code.

**`dowscripts/CONTRIBUTING.md`**
- Step-by-step: how to register a new script (config entry, `ParamDefinition`s, tests)
- Coding standards: Laravel Pint / PSR-12, standard Laravel conventions (thin
  controllers, form requests for validation, jobs for async work)
- Test requirements: what must ship with a PR, the "never shell out for real in tests"
  rule from §8 stated explicitly
- Local setup (migrations, seeders, env)
- Secrets handling: never commit real credentials, even in tests/fixtures
