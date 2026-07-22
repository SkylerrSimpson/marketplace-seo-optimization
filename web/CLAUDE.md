# DOWScripts — instructions for Claude Code

DOWScripts' web app (this `web/` directory) is a Laravel UI in front of the existing
marketplace CLI scripts, which live one level up under `marketplaces/`
(`../marketplaces/ebay/scripts/`, `../marketplaces/shopify/scripts/`, ...). Read
`PLAN.md` (this directory) for the full design, and `../ARCHITECTURE.md` for how the
two halves connect — this file is the short version: the rules that must hold on
every change, not the reasoning behind them.

## The one rule everything else follows from

**This app never reimplements marketplace logic.** It only ever shells out to the
existing scripts as subprocesses — including their `--live` flag for real writes. If
you find yourself writing eBay API calls, Shopify API calls, or any marketplace-specific
business logic directly inside `app/`, stop — that logic belongs in the script it's
wrapping, not here. Fix or extend the script, then wrap the fixed version.

This is not a style preference. Those scripts encode real, hard-won correctness that
cost real debugging time to get right the first time, and DOWScripts has no track record
of its own to replace it with:

- **The vary-by guard** — never rewrite a listing's variation-defining aspect value.
  Doing so orphans the listing's eBay sales history. `apply_aspects.php` always resends
  a variation listing's full, unchanged `VariationSpecifics` for exactly this reason.
- **The MULTI-cardinality comma-split fix** — some eBay allowed values contain a comma
  themselves (e.g. Theme's `"Cartoon"` vs `"Cartoon, TV & Movie Characters"` are two
  different single picklist entries, not one value plus a naive split producing two).
  A naive `explode(',', $value)` on a MULTI aspect is a live bug waiting to happen.
- **The SEOInput full-replace lesson** (Shopify side) — omitting a field from an update
  payload nulls it out; it does not leave the existing value alone. This shape of bug
  (assuming omission means "no change" when the API actually treats it as "clear this")
  is exactly the kind of thing a fresh reimplementation would silently reintroduce.

If you're editing `RunScriptJob`, a `ScriptDefinition`, or any `ParamDefinition` — even
though this app doesn't touch that marketplace logic directly — you need to understand
why these rules exist, because the params/flags you wire up are what let a user
accidentally (or safely) trigger them.

## Registry-driven, not one-class-per-script

Scripts are described as **data** (PHP config arrays, one per marketplace — e.g.
`config/scripts/ebay.php`), loaded into typed `ScriptDefinition` DTOs by `ScriptRegistry`
at boot. Adding a new script to the UI means adding a config entry (+ tests), never a new
controller or a new class. If a change to this app requires a new class for every script
it wraps, something has gone wrong in the design — flag it before proceeding.

## TDD is mandatory here, not optional

See `CONTRIBUTING.md` for the concrete rules. The short version: every PR ships with
tests, and tests **never shell out to a real script or hit a real marketplace API** —
`ProcessRunner` is faked in every test. That correctness lives in, and stays owned by,
the wrapped scripts; re-testing it here would be redundant at best and risky at worst (a
test accidentally hitting production).

## Safety model — do not weaken this in the UI

The CLI's existing guardrails must have an exact web equivalent, never an approximation:

1. Any `Write`-type script's run page shows a dry-run/preview result first. There is no
   path that skips straight to a live call.
2. A single-item live write requires retyping the item ID in a confirmation modal.
3. A bulk live write (more than one item) requires typing the literal word `WRITE`.
4. Every confirmation (what was typed, by whom, when) is recorded on the `ScriptRun` row.

If a change makes any of these easier to bypass — even accidentally, even for a "just
this once" admin shortcut — treat it as a bug, not a feature request.
