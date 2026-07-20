# Amazon — SP-API Catalog Pipeline

A standalone PHP pipeline that audits the IGE and DOWS Amazon catalogs
against Amazon's per-productType requirements, fills attribute gaps from
Usurper (or authors them with Claude when no source exists), and writes the
result back to Amazon behind a guarded, reversible write path — then projects
the AI-authored values back into Usurper so the catalog self-heals over time.

It mirrors the shape of the Shopify pipeline (read-only audit/export → author
→ reviewable output → guarded write) but is adapted to Amazon's constraints:
no "list-all" listings endpoint, per-productType schemas, and identifying/
compliance attributes that are dangerous to write blindly.

> **Status:** Complete and operational for both IGE and DOWS. Read →
> analyze → AI-draft → guarded write-back → Usurper projection, plus a
> write-safety layer (backups, restore, identifying/compliance/shrink guards),
> a read-only variation-reconciliation diagnostic, and a deterministic
> catalog-drift snapshot that tracks catalog change over time.

---

## Table of contents

**Get started**

- [What it does](#what-it-does)
- [Setup](#setup)
- [Usage](#usage)

**Reference / deep dive**

- [Amazon — SP-API Catalog Pipeline](#amazon--sp-api-catalog-pipeline)
  - [Table of contents](#table-of-contents)
  - [What it does](#what-it-does)
  - [Setup](#setup)
  - [Usage](#usage)
  - [How the pipeline fits together](#how-the-pipeline-fits-together)
  - [The three data layers](#the-three-data-layers)
  - [Directory layout](#directory-layout)
  - [The pipeline, phase by phase](#the-pipeline-phase-by-phase)
    - [Phase 0 — connectivity check](#phase-0--connectivity-check)
    - [Phase 1 — catalog export (Reports API)](#phase-1--catalog-export-reports-api)
    - [Phase 2 — per-SKU listings snapshot](#phase-2--per-sku-listings-snapshot)
    - [Phase 3 — per-ASIN catalog snapshot](#phase-3--per-asin-catalog-snapshot)
    - [Phase 4 — product-type schema cache](#phase-4--product-type-schema-cache)
    - [Phase 5 — audit](#phase-5--audit)
    - [Phase 6 — gap-fill analysis](#phase-6--gap-fill-analysis)
    - [Phase 6.5 — modular title generation](#phase-65--modular-title-generation)
    - [Phase 6.6 — title review (manual)](#phase-66--title-review-manual)
    - [Phase 7 — AI-assisted draft generation](#phase-7--ai-assisted-draft-generation)
    - [Phase 8 — write-back to Amazon](#phase-8--write-back-to-amazon)
      - [Write-safety layer](#write-safety-layer)
      - [8.1 Identifying-attribute guard](#81-identifying-attribute-guard)
      - [8.2 Shrink-guard for multi-valued attributes](#82-shrink-guard-for-multi-valued-attributes)
      - [8.3 Backup \& restore](#83-backup--restore)
      - [8.4 `-NCX` / `-FBA` placeholder templating](#84--ncx---fba-placeholder-templating)
      - [8.5 Always-present compliance attributes](#85-always-present-compliance-attributes)
    - [Phase 9 — project drafts to Usurper](#phase-9--project-drafts-to-usurper)
    - [Variation reconciliation (Phase 10)](#variation-reconciliation-phase-10)
    - [Catalog / listing drift snapshot (Phase 11)](#catalog--listing-drift-snapshot-phase-11)
  - [Output formats reference](#output-formats-reference)
  - [Deferred / out of scope](#deferred--out-of-scope)
  - [Reference: patterns borrowed from Usurper](#reference-patterns-borrowed-from-usurper)

---

## What it does

The end goal is a **complete, compliant Amazon catalog** for both seller
accounts, kept in sync with Usurper, without ever risking a listing through a
careless write.

Concretely, the pipeline answers four questions and acts on them:

1. **What do we have on Amazon?** — enumerate every SKU/ASIN (Phases 1–3).
2. **What does Amazon require that we're missing?** — compare each listing to
   its product-type schema (Phases 4–5).
3. **What can we fill, and from where?** — resolve gaps against Usurper data;
   author the rest with Claude (Phases 6–7).
4. **How do we push it safely and keep both systems in sync?** — guarded
   write-back to Amazon with backups + restore, and a projection back into
   Usurper (Phases 8–9), plus a diagnostic for broken variation families
   (Phase 10) and a drift snapshot that tracks catalog change over time
   (Phase 11).

Single marketplace at launch: **US (`ATVPDKIKX0DER`)**. Every script defaults
to **production** credentials (`AMAZON_SPAPI_SANDBOX=false`); Phases 0–7 and
9–11 are read-only or local, so they are safe to run against prod. Only
Phase 8 (`patch_listings.php --apply`) and `restore_listings.php --apply`
write to Amazon, and both are dry-run by default.

Two accounts are supported everywhere via `--account=IGE|DOWS` (default
`IGE`). Credentials are shared — Usurper's SP-API app, two consumers — with a
`_DOWS` env-suffix convention.

---

## Setup

Requires PHP 8.1+ and Composer. Two dependencies drive the pipeline:

- [`jlevers/selling-partner-api`](https://github.com/jlevers/selling-partner-api)
  — the raw, Saloon-based SP-API SDK (same library Usurper wraps). The
  `SellingPartnerApi::seller(...)` connector handles refresh tokens internally.
- [`anthropic-ai/sdk`](https://github.com/anthropic-ai/sdk) — the official
  Anthropic PHP SDK, used by Phase 6.5 (titles) and Phase 7 (attributes).
- [`openai-php/client`](https://github.com/openai-php/client) — the OpenAI PHP
  SDK, used by Phase 6.5 to generate the competing title candidates.

```bash
composer install
cp .env.example .env    # fill in the Amazon block (copy from Usurper's .env)
```

Required `.env` values (US marketplace, IGE + `_DOWS` variants):

- SP-API app credentials (client id/secret, refresh token, LWA), seller id,
  `AMAZON_SPAPI_MARKETPLACE_ID=ATVPDKIKX0DER`, `AMAZON_SPAPI_SANDBOX=false`.
- `ANTHROPIC_API_KEY` — required for Phase 6.5 (titles) and Phase 7 (attributes).
- `OPENAI_API_KEY` — required for Phase 6.5 unless `--provider=anthropic`.

`lib/bootstrap.php` defines the path constants and an `amazon_paths($account)`
helper that returns account-scoped paths and creates directories on first use.
Every script begins with:

```php
$amazon = new AmazonClient('IGE'); // or 'DOWS'
$paths  = amazon_paths('IGE');
```

Every script supports `--help` and `--account=IGE|DOWS`.

---

## Usage

The whole pipeline is a sequence of `php amazon/scripts/<x>.php --account=IGE`
commands. A complete cold-start run for one account (IGE shown; substitute
`DOWS`) — each numbered step maps to the like-numbered phase documented under
[The pipeline, phase by phase](#the-pipeline-phase-by-phase):

```bash
composer install
cp .env.example .env    # fill in the Amazon block

# 0 — connectivity
php amazon/scripts/check_connection.php --account=IGE

# 1–3 — fetch data
php amazon/scripts/export_listings_report.php --account=IGE
php amazon/scripts/export_listings_items.php --account=IGE
php amazon/scripts/export_catalog_items.php  --account=IGE

# 4 — schema cache (scans all accounts automatically)
php amazon/scripts/fetch_product_type_schemas.php

# 5–6 — audit + gap-fill (drop Usurper InventoryExport into usurper/ first)
php amazon/scripts/audit_listings.php --account=IGE
php amazon/scripts/analyze_gap_fill.php --account=IGE

# 6.5 — modular titles (both providers → compare/{sku}.json + title_decisions.csv)
php amazon/scripts/generate_titles.php --account=IGE --dry-run
php amazon/scripts/generate_titles.php --account=IGE

# 6.6 — MANUAL: open output/title_decisions.csv and set each *_pick column

# 7 — draft (dry-run, then write; folds compare/ title options into the draft)
php amazon/scripts/draft_listings.php --account=IGE --dry-run
php amazon/scripts/draft_listings.php --account=IGE
# → review the committed drafts/{sku}.json before writing anything to Amazon

# 8 — write-back (dry-run, then apply; --include-titles to also patch reviewed titles)
php amazon/scripts/patch_listings.php --account=IGE                          # dry-run
php amazon/scripts/patch_listings.php --account=IGE --apply --include-titles

# 9 — project back to Usurper (includes reviewed titles), then import the CSV
php amazon/scripts/project_to_usurper.php --account=IGE

# 10 — variation diagnostic (any time; read-only)
php amazon/scripts/analyze_variations.php --account=IGE

# 11 — drift snapshot (any time; read-only, commit to track catalog drift)
php amazon/scripts/drift_snapshot.php --account=IGE
```

**Acceptance checks:**

- `input/reports/` has `listings_*.tsv` and `suppressed_*.tsv`.
- `input/listings/` has one JSON per SKU; `input/catalog/` one per unique ASIN,
  with 404s in `catalog/errors/`.
- `data/schemas/` has the schema files + a populated `_index.json`.
- `output/listings_audit.csv` exists, sorted by priority.
- `output/listings_gap_fill.csv` — `fillable` rows have a non-empty
  `usurper_column` and `usurper_value`.
- `drafts/*.json` — `source: usurper` entries carry `usurper_column`; enum
  values are valid schema members.
- `output/patch_results_{ts}.csv` after Phase 8 (`status=dry_run` on a dry-run).
- `backups/{sku}/{ts}.json` for each SKU touched by `--apply`.
- `output/usurper_update_{ts}.csv` after Phase 9 (`attr.amazon_*` = new,
  `attr.*` = existing Usurper fields).
- `drift/snapshot.json` after Phase 11; a second run on unchanged inputs leaves
  it byte-identical (empty `git diff`).

---

> **Everything below is reference / deep-dive material** — the architecture, the
> data model, the per-phase detail, and the output formats. You don't need any
> of it to run the tool; read on if you're extending the pipeline or want to
> understand _why_ it's shaped the way it is.

## How the pipeline fits together

```
Phases 0–4  fetch reference data (SP-API → disk)
   │          reports, per-SKU listings, per-ASIN catalog, product-type schemas
   ▼
Phase 5   audit_listings.php        → listings_audit.csv        (what's missing, ranked)
   ▼
Phase 6   analyze_gap_fill.php      → listings_gap_fill.csv     (fillable vs needs-authoring)
   ▼
Phase 6.5 generate_titles.php       → compare/*.json + title_decisions.csv  (Anthropic vs OpenAI)
   │      Phase 6.6 (manual)         → reviewer sets *_pick in title_decisions.csv
   ▼
Phase 7   draft_listings.php        → drafts/{sku}.json         (committed, human-reviewable)
   │                                        │
   ▼                                        ▼
Phase 8   patch_listings.php        →  Amazon SP-API            (guarded write; --apply required)
Phase 9   project_to_usurper.php    →  usurper_update_{ts}.csv  (import into Usurper)

Phase 10  analyze_variations.php    → variation_analysis_{ts}.csv (read-only diagnostic)
Phase 11  drift_snapshot.php        → drift/snapshot.json           (deterministic drift digest)
```

**The self-healing loop:** after a Phase 9 import, the next Usurper
InventoryExport carries the new `attr.amazon_*` columns. On the next run,
Phase 6 classifies those attributes as `fillable` — no AI call needed. AI cost
collapses toward zero for stable products after the first round-trip.

---

## The three data layers

Nearly every design decision in this pipeline rests on distinguishing three
things Amazon exposes that are **not** interchangeable. Keeping them separate is
what lets the audit compare against the right baseline and lets Phase 10 detect
that "what we asserted" has drifted from "what Amazon realized."

1. **Listing** (Listings Items API → Phase 2) — _what we submitted for our SKU_,
   plus Amazon's validation verdict in the `issues[]` array. This is our model,
   and it can be non-compliant.
2. **Schema** (Product Type Definitions API → Phase 4) — **Amazon's
   rules/requirements**. A listing is validated against the schema; violations
   surface as entries in the listing's `issues[]`. The schema is the rulebook —
   the catalog is not.
3. **Catalog** (Catalog Items API → Phase 3) — **Amazon's realized, merged,
   public** record for the ASIN, aggregated across every seller on it. This is
   what shoppers actually see: "what Amazon did with it," not "what Amazon
   requires."

The listing (what we assert) can diverge from the catalog (what Amazon
realized) — that divergence is exactly the class of defect Phase 10 detects
(confirmed live: a SKU whose _listing_ asserts a `VARIATION` parent + `SIZE`
theme while its _catalog_ record shows no relationships at all).

**Identifying vs non-identifying attributes** is the other cross-cutting
distinction, and it governs the write path:

- **Non-identifying** — descriptive/content attributes (bullets, material,
  color descriptors, keywords, dimensions). Safe to write. **Default write scope.**
- **Identifying** — attributes that classify a listing or bind it into a
  variation family, where a wrong value can suppress the listing or detach a
  variant: `variation_theme` (+ its theme attributes), UPC/EAN/external product
  IDs, `product_type`, `item_type*`, `item_quantity`, `number_of_items`, and the
  variation-defining attributes each product type's schema names. **Never
  written by default** — see [Phase 8](#phase-8--write-back-to-amazon).

---

## Directory layout

Data directories are **account-scoped** (`ige/`, `dows/`). Schemas are shared
across accounts and live outside the account tree.

```
amazon/
├── README.md             ← this file
├── data/
│   ├── {account}/        ← one subtree per seller account (ige, dows)
│   │   ├── input/                             (gitignored — regenerable)
│   │   │   ├── reports/   ← listings_{ts}.tsv + .json, suppressed_{ts}.tsv + .json
│   │   │   ├── listings/  ← {sku}.json  per-SKU getListingsItem snapshot
│   │   │   ├── catalog/   ← {asin}.json per-ASIN getCatalogItem snapshot
│   │   │   │   └── errors/  ← {asin}.json structured error envelope for 404s
│   │   │   └── usurper/   ← InventoryExport_{ts}.csv (drop Usurper dumps here)
│   │   ├── compare/       ← {sku}.json Anthropic-vs-OpenAI title candidates (committed)
│   │   ├── drafts/        ← {sku}.json authored attribute drafts   (committed)
│   │   ├── backups/       ← {sku}/{ts}.json pre-change snapshots    (committed)
│   │   ├── drift/         ← snapshot.json deterministic drift digest (committed)
│   │   └── output/        ← audit / gap-fill / patch-result / variation CSVs (gitignored)
│   └── schemas/           ← cached Product Type Definition schemas  (committed, shared)
│       ├── _index.json    ← {productType: {fetched_at, version, locale, source_url}}
│       └── {PRODUCT_TYPE}.json
├── scripts/              ← PHP entrypoints (run via `php amazon/scripts/<x>.php`)
└── lib/ (repo root)     ← AmazonClient, AmazonPatch, IdentifyingAttributes,
                            ComplianceAttributes, ComplianceResolvers,
                            DefaultAttributes, HighValueAttributes,
                            UsurperAttributeMap, AmazonRateLimits, …
                            Amazon/Ai/ (PSR-4 Ige\Amazon\Ai\ — title providers,
                            prompts, ModularTitleGenerator; Phase 6.5)
```

**What's committed vs gitignored:** authored content is source of truth and is
tracked — `compare/`, `drafts/`, `backups/`, and `drift/` are committed; so is
`data/schemas/` (small, high-value, reviewable, and protection against
accidental re-downloads). Everything under `input/` and the `output/*.csv|*.txt`
files are gitignored (regenerable, potentially large). `backups/` and `drift/`
sit _outside_ `input/` specifically so they stay tracked — `drift/` in
particular is the committed digest of the gitignored `input/` snapshots.

---

## The pipeline, phase by phase

Each phase below lists **what it's for**, **when to run it**, and **how to
invoke it**. All scripts accept `--account=IGE|DOWS` (default `IGE`), `--help`,
and — where a run can be expensive — `--limit=N` for canary runs and `--force`
to overwrite existing output.

### Phase 0 — connectivity check

**Use case:** confirm SP-API credentials and endpoint are wired correctly
before doing anything else. Prints endpoint, marketplace, and seller id.

```bash
php amazon/scripts/check_connection.php --account=IGE
php amazon/scripts/check_connection.php --account=DOWS
```

Exits 0 on success. Run this first whenever credentials change.

### Phase 1 — catalog export (Reports API)

**Use case:** Amazon has no "list all my listings" endpoint, so the Reports API
is our spine. This produces the **SKU → ASIN map** every later phase depends
on, plus a list of suppressed listings.

Requests two reports upfront so Amazon processes them in parallel:
`GET_MERCHANT_LISTINGS_ALL_DATA` (active) and `GET_MERCHANTS_LISTINGS_FYP_REPORT`
(suppressed). Polls with 30s→120s exponential backoff (~15 min max), then
downloads and writes a raw TSV plus a normalized JSON sidecar keyed by
`seller-sku` (the `asin1` field is the SKU→ASIN map).

```bash
php amazon/scripts/export_listings_report.php --account=IGE
php amazon/scripts/export_listings_report.php --account=DOWS
php amazon/scripts/export_listings_report.php --account=IGE --force   # re-request today
```

Idempotent: skips both reports if today's files already exist unless `--force`.

### Phase 2 — per-SKU listings snapshot

**Use case:** capture **what we told Amazon** for every SKU — our submitted
attributes plus Amazon's validation `issues[]`. This is the model the audit
compares against the schema.

Uses paginated `searchListingsItems` (pageSize 20). Amazon caps that endpoint
at 1,000 results, so accounts over the cap (DOWS: ~4,620 SKUs) fall back to
`open-date` date-range chunking, then to per-SKU `getListingsItem` for any
stragglers. Writes lossless raw JSON per SKU.

```bash
php amazon/scripts/export_listings_items.php --account=IGE --limit=5   # canary
php amazon/scripts/export_listings_items.php --account=IGE
php amazon/scripts/export_listings_items.php --account=DOWS             # date-range chunking kicks in
```

Idempotent per SKU (skips existing files unless `--force`). Requires Phase 1
for the open-date data used by chunking.

### Phase 3 — per-ASIN catalog snapshot

**Use case:** capture **what Amazon's catalog actually shows** for each ASIN —
the merged, public view with browse-tree classifications, sales ranks, and the
aggregated attribute set. Gap analysis and variation reconciliation need both
this and the Phase 2 listing view.

Reads the latest report sidecar, extracts unique `asin1` values, and calls
`getCatalogItem`. Successes → `catalog/{asin}.json`; non-200s (mostly 404s for
delisted products) → `catalog/errors/{asin}.json` as a structured envelope
rather than a dropped record.

```bash
php amazon/scripts/export_catalog_items.php --account=IGE --limit=5    # canary
php amazon/scripts/export_catalog_items.php --account=IGE
php amazon/scripts/export_catalog_items.php --account=DOWS
```

Idempotency checks both `catalog/` and `catalog/errors/`, so 404'd ASINs
aren't re-fetched. On `--force`, an ASIN that flips outcome
(success↔error) has its stale file in the opposing directory removed.

### Phase 4 — product-type schema cache

**Use case:** to know what Amazon _requires_ for a listing you need its
product-type schema. This discovers every product type in use across both
accounts and caches each schema to disk as a reviewable, committed reference.

Scans all `*/input/listings/` for distinct `summaries[*].productType` values,
then fetches each via the Product Type Definitions API (using IGE credentials —
schemas are account-agnostic). Writes `data/schemas/{PRODUCT_TYPE}.json` and
updates `_index.json` after each, so partial runs resume safely.

```bash
php amazon/scripts/fetch_product_type_schemas.php          # scans all accounts
php amazon/scripts/fetch_product_type_schemas.php --force  # re-fetch cached types
```

No-op for already-cached types unless `--force`.

> **Modular titles (effective 2026-07-27):** Amazon splits titles into
> `item_name` (≤75 chars) + a new `title_differentiation` (≤125). Because
> Phase 7's length caps and title handling are schema-driven, **re-fetch schemas
> after that date** (`fetch_product_type_schemas.php --force`) to pick up the new
> `title_differentiation` attribute and the `item_name` cap — no code change
> needed, it activates from the refreshed schema.

### Phase 5 — audit

**Use case:** the ranked "what's missing" report. For each SKU, compares the
listing's attributes against the schema's required + recommended sets and emits
a priority-scored CSV — the Amazon counterpart to Shopify's `audit_products.php`.

Read-only, no API calls (works entirely from the Phase 2–4 snapshots on disk).

```bash
php amazon/scripts/audit_listings.php --account=IGE
php amazon/scripts/audit_listings.php --account=DOWS
```

Output: `output/listings_audit.csv`, one row per SKU (ASIN, productType,
missing-required/recommended counts, top missing attribute names, current
issue count, priority score), sorted by priority.

### Phase 6 — gap-fill analysis

**Use case:** decide, for every missing attribute, whether Usurper already has
the data (`fillable`) or a human/AI must supply it (`needs_authoring`). This is
what turns the audit into an actionable work list.

Re-derives the full missing-attribute list per SKU (not just Phase 5's top-5
summary), then resolves each gap against `lib/UsurperAttributeMap.php` — an
ordered preference list of Usurper columns per Amazon attribute. First
non-empty column wins; `bullet_point` is multi-source (`attr.feature01`–`05`).
Any attribute with no explicit map entry is also checked against the
`attr.amazon_{name}` convention, which is how the self-healing loop closes.

**Before running:** drop the Usurper InventoryExport CSV(s) into the account's
`usurper/` directory. Any `*.csv` there is accepted; most-recent by mtime wins.

```bash
# amazon/data/ige/input/usurper/InventoryExport_YYYY-MM-DD-HH-MM-SS.csv
# amazon/data/dows/input/usurper/InventoryExport_YYYY-MM-DD-HH-MM-SS.csv
php amazon/scripts/analyze_gap_fill.php --account=IGE
php amazon/scripts/analyze_gap_fill.php --account=DOWS
```

Output: `output/listings_gap_fill.csv`, one row per SKU/attribute pair
(`fillable` rows name the source column and value; SKUs absent from the Usurper
dump are flagged `sku_not_in_usurper` rather than silently dropped). Prints a
summary of SKUs affected and fillable-vs-authoring counts.

### Phase 6.5 — modular title generation

**Use case:** produce competing, human-reviewable candidates for the two Amazon
**modular title** attributes — `item_name` (≤75 chars) and `title_differentiation`
(≤125 chars) — from **two** LLM providers, so a reviewer can pick the best output.

For each gap-fill SKU (or a single `--sku`), the tool assembles one product context
(brand from the catalog snapshot, the existing title, description, `bullet_point`
features, and `generic_keyword` search terms) and asks **each provider** — Anthropic
and OpenAI — for both attributes in a single combined call
(`lib/Amazon/Ai/ModularTitleGenerator`). The item_name prompt drives a token format
(`${brand} ${pack_size}-${pack_size_unit} ${size} ${color} ${name}`) and returns the
parsed tokens alongside the assembled title; each candidate's char count and any
over-cap violation are recorded.

```bash
# Requires ANTHROPIC_API_KEY and OPENAI_API_KEY (or narrow with --provider=)
php amazon/scripts/generate_titles.php --account=IGE --dry-run
php amazon/scripts/generate_titles.php --account=IGE
php amazon/scripts/generate_titles.php --account=IGE --sku=SOME-SKU --provider=anthropic
```

Flags: `--provider=both|anthropic|openai` (default both), `--anthropic-model=`
(default `claude-sonnet-5`; aliases `haiku`/`sonnet`/`opus`), `--openai-model=`
(default `gpt-4o`), `--force` to overwrite, `--dry-run`.

Output, three artifacts:
- `compare/{sku}.json` — both providers' candidates side by side, per SKU (committed).
- `output/title_compare.csv` — read-only report, **fully rebuilt** every run.
- `output/title_decisions.csv` — the **editable decision sheet** (Phase 6.6). Seeded
  from the compare files with both candidates plus an empty `*_pick` / `*_final`
  column per attribute. Re-running **preserves** any picks already entered, so it is
  safe to regenerate. This is the file a reviewer marks up.

This phase only emits artifacts — no drafts, no writes.

### Phase 6.6 — title review (manual)

Open `output/title_decisions.csv` and, per row, set each `*_pick` to `anthropic`,
`openai`, `custom` (then type the value into the matching `*_final` column), or
`skip` / blank to leave that attribute alone:

| column | meaning |
|---|---|
| `item_name_anthropic` / `item_name_openai` | the two candidates (read-only) |
| `item_name_pick` | `anthropic` \| `openai` \| `custom` \| `skip` |
| `item_name_final` | your text, used only when `item_name_pick = custom` |
| `td_*` | the same four columns for `title_differentiation` |

The chosen values feed both Phase 8 (`--include-titles`) and Phase 9. Nothing is
patched or projected until you make picks here.

### Phase 7 — AI-assisted draft generation

**Use case:** produce a committed, human-reviewable draft per SKU. Fillable
attributes are resolved directly from Usurper; `needs_authoring` attributes are
authored by Claude with schema-constrained prompts (enum **and `maxLength`**
values are validated, so invalid values can't slip through).

Reads the Phase 6 CSV grouped by SKU (highest-gap-score first), reloads the
Usurper export for full untruncated values, and for each SKU batches its
authoring attributes into Anthropic calls carrying product context + the
per-attribute schema constraints. Three cost/quality controls keep spend sane
(Amazon schemas carry ~130 optional attributes per product type of which only
~7 are required):

- **Scope** — by default authors only **required + a curated high-value
  allowlist** (`lib/HighValueAttributes.php`: title, bullets, description,
  keywords, brand, key descriptors). The full optional tail is opt-in via
  `--include-recommended`.
- **Model tiering** (`--model=auto`, default) — **Haiku** for enum/short-string
  fills, **Sonnet** for prose, **Opus** only for the marquee title/description
  set. Each AI entry records its own `model`. `--model=X` forces one model.
- **Data-gate** — a SKU with no title/description/features would make the model
  invent everything from a SKU string. Such SKUs are skipped and flagged
  `status: needs_human`, **except** `-FBA`/`-NCX` placeholders, which borrow
  their base SKU's context (same-ASIN/product_type guarded). `--no-data-gate`
  disables this.

**Modular titles** (Amazon, effective 2026-07-27): `item_name` (≤75) and
`title_differentiation` (≤125), combined ≤200, with `title_differentiation` only
valid when `item_name ≤ 75`. These two are **not authored here** — Phase 6.5
(`generate_titles.php`) writes both providers' candidates to `compare/{sku}.json`,
and the drafter folds each into the draft under a **review-only** key
(`item_name_ai_{provider}` / `title_differentiation_ai_{provider}`), carrying the
provider, model, char count, parsed tokens (item_name), and any over-cap
`validation_error`. The live `item_name` is never overwritten and nothing is
auto-patched: a human picks the winning provider, and the coupling rule
(`item_name ≤ 75`, pair ≤ 200) is validated at patch time. When a SKU has no
compare file, the drafter notes it and skips the title keys.

**Variation-member safety** (stakeholder rule): for an item that is part of a
variation family, its actual variation-theme attributes (read from the item's
own `relationships` — e.g. `material`, or `size`+`color`) are **excluded from AI
authoring** so the model can't invent a value that splits/merges the family. The
ASIN's own authoritative value may still come from its catalog snapshot (tagged
`identifying` + `variation_member`), and stays held back at patch time behind
`--include-identifying` (see 8.1).

**Default attributes** (`lib/DefaultAttributes.php`): a hand-editable layer the
drafter applies fill-missing only, in scope where the schema defines the
attribute. Two kinds:
- **null defaults** (`compliance_media`, `fcc_radio_frequency_emission_compliance`,
  `government_contract_information`, `non_lithium_battery_*`, `fulfillment_availability`,
  `supplemental_condition_information`, …) — recorded with `source: "default"`,
  `value: null` for review, **never patched** (`patch_listings.php` skips null).
- **value defaults** — `product_tax_code → A_GEN_TAX`; `unit_count → count/1`
  **only when the SKU is not a variation member**. These patch through the
  normal candidate path. Neither overwrites an existing listing/draft value.

```bash
# Requires ANTHROPIC_API_KEY in .env
php amazon/scripts/draft_listings.php --account=IGE --dry-run    # preview + cost estimate
php amazon/scripts/draft_listings.php --account=IGE              # writes drafts/
php amazon/scripts/draft_listings.php --account=DOWS --dry-run
php amazon/scripts/draft_listings.php --account=DOWS

# Author the full optional tail / bypass the data-gate:
php amazon/scripts/draft_listings.php --account=IGE --include-recommended
php amazon/scripts/draft_listings.php --account=IGE --no-data-gate
php amazon/scripts/draft_listings.php --account=IGE --full            # both; the old exhaustive draft
php amazon/scripts/draft_listings.php --account=IGE --full --model=opus

# Force a single model (overrides auto):
php amazon/scripts/draft_listings.php --account=IGE --model=claude-sonnet-5

# Single-SKU test:
php amazon/scripts/draft_listings.php --account=IGE --sku=IGE-PENLIGHT

# -NCX/-FBA placeholder templating (see 8.4):
php amazon/scripts/draft_listings.php --account=IGE --template-placeholders
```

`--dry-run` prints a per-model cost estimate before you spend anything.
Idempotent (skips existing drafts unless `--force`). Compliance-critical
attributes are never AI-authored (see [Phase 8](#phase-8--write-back-to-amazon)).

**Draft format** (`drafts/{sku}.json`):

```json
{
  "sku": "UPD-COLORBOOK-BBY-20820",
  "asin": "B0EXAMPLE",
  "product_type": "ART_CRAFT_KIT",
  "account": "DOWS",
  "generated_at": "2026-06-29T22:37:25+00:00",
  "model": "auto",
  "status": "ok",
  "context_source": "self",
  "totals": { "fillable": 3, "ai_suggested": 2, "ai_null": 1 },
  "attributes": {
    "brand": {
      "value": "Disney",
      "is_required": true,
      "source": "usurper",
      "usurper_column": "attr.brand_amazon"
    },
    "bullet_point": {
      "value": ["Arts & crafts coloring book", "20 pages"],
      "is_required": false,
      "source": "usurper",
      "usurper_column": "attr.feature01, attr.feature02"
    },
    "supplier_declared_dg_hz_regulation": {
      "value": "not_applicable",
      "is_required": true,
      "source": "ai"
    },
    "country_of_origin": {
      "value": null,
      "is_required": false,
      "source": "ai"
    }
  }
}
```

### Phase 8 — write-back to Amazon

**Use case:** push the reviewed drafts to Amazon. This is the one write path,
and it is hardened by the [write-safety layer](#write-safety-layer) below:
dry-run by default, per-SKU pre-change backups, and identifying/compliance/
shrink guards.

Reads `drafts/{sku}.json`, formats each attribute into an SP-API PATCH
(`op: replace` per attribute path, so untouched attributes are left alone),
and submits via `patchListingsItem`. Skips `null` values, any attribute flagged
`validation_error`, and `review_only` entries (the per-provider title candidates)
— only clean values reach Amazon. Throttles and retries 429s via
`AmazonRateLimits::retryWithBackoff()`.

**Modular titles** (`--include-titles`): `item_name` / `title_differentiation` are
never patched from the draft. With `--include-titles`, the reviewer's chosen values
are read from `output/title_decisions.csv` (Phase 6.6) via `Ige\Amazon\Ai\TitleDecisions`
and patched, subject to the coupling guard — `item_name` over 75 chars is dropped, and
`title_differentiation` is dropped (not the whole SKU) when the effective `item_name`
(the one being patched, else the live listing's) is over 75 or the pair exceeds 200.
Default off, mirroring `--include-identifying`.

```bash
# Dry-run (no API calls) — always review this first:
php amazon/scripts/patch_listings.php --account=IGE
php amazon/scripts/patch_listings.php --account=DOWS

# Apply (writes a pre-change backup per touched SKU, then submits):
php amazon/scripts/patch_listings.php --account=IGE --apply
php amazon/scripts/patch_listings.php --account=DOWS --apply

# Also patch the reviewed modular titles from output/title_decisions.csv:
php amazon/scripts/patch_listings.php --account=IGE --apply --include-titles

# Single-SKU apply:
php amazon/scripts/patch_listings.php --account=IGE --sku=IGE-PENLIGHT --apply

# Include identifying data (variation theme, IDs, etc. — see 8.1):
php amazon/scripts/patch_listings.php --account=IGE --apply --include-identifying
php amazon/scripts/patch_listings.php --account=IGE --apply --include-identifying=variation_theme
```

Records results to `output/patch_results_{ts}.csv` with per-SKU status
(ACCEPTED / WITH_WARNINGS / INVALID / ERROR), submission ids, and any Amazon
issue messages. A dry-run writes the same CSV with `status=dry_run` so scope is
auditable before committing.

#### Write-safety layer

Phase 8 is hardened against a set of explicit stakeholder concerns about the
write path. Each guard below traces back to one of them:

| Concern                                                                                  | Guard                                                                                           | Where |
| ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- | ----- |
| "Must not lose a listing / must not impact sales." Identifying data is the danger.       | Identifying-attribute guard — held back unless `--include-identifying`.                         | 8.1   |
| "Be aware of partial vs full update; don't delete information."                          | PATCH `op: replace` per attribute (others untouched) + shrink-guard on multi-valued attributes. | 8.2   |
| "Store current state; be able to restore a listing."                                     | Pre-change live snapshots committed to git + `restore_listings.php`.                            | 8.3   |
| `-NCX`/`-FBA` placeholders _do_ need identifying data, sourced from their base SKU.      | Union/fill-missing templating from the base's listing snapshot.                                 | 8.4   |
| An open-ended list of compliance attributes must _always_ be filled.                     | Compliance list + resolvers; AI barred; unsourced no-resolver attr hard-blocks the SKU.         | 8.5   |
| `item_quantity` / `number_of_items` are identifying and shouldn't be written by default. | On the curated identifying list.                                                                | 8.1   |
| "Don't modify a variation member's theme attributes (`material`, `size`+`color`)."       | AI never authors them; the item's own `relationships` theme attrs join the identifying guard.   | 8.1   |
| Sharpening stones need a Prop 65 **airborne-silica** warning, not lead.                   | `SILICA_PRODUCT_TYPES` in `ComplianceResolvers` (precedence over lead).                          | 8.5   |
| A set of compliance/behavior attributes need explicit defaults (mostly null).            | `lib/DefaultAttributes.php` fill-missing layer; nulls document-only, `product_tax_code`/`unit_count` real. | Phase 7 |
| Modular titles cap `item_name` at 75 chars; want a reviewable AI title.                  | `item_name_ai_suggested` (Opus, ≤75, `review_only` — never patched).                            | Phase 7 |

These guards are always on in `patch_listings.php`; the flags below relax them.

**Write-path decision matrix (`--apply`):**

| Attribute class                                                                                                                        | Default                                        | With `--include-identifying` |
| -------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- | ---------------------------- |
| Non-identifying content                                                                                                                | **written**                                    | written                      |
| Compliance                                                                                                                             | **written** (or SKU hard-blocked if unsourced) | same                         |
| `review_only` suggestion (`item_name_ai_suggested`) / null default                                                                     | **never written**                              | never written                |
| Multi-valued, shorter than live                                                                                                        | **skipped + warned** (unless `--allow-shrink`) | same                         |
| Identifying (`variation_theme`, product IDs, `product_type`, `item_type*`, `item_quantity`, `number_of_items`, schema variation attrs) | **skipped + logged**                           | written                      |

#### 8.1 Identifying-attribute guard

Identifying attributes classify a listing or bind it into a variation family —
a wrong value can suppress the listing or detach a variant. They are **held
back by default**. `lib/IdentifyingAttributes.php` is a hybrid definition: a
hand-maintained curated list _plus_ the variation-defining attributes each
product type's cached schema declares (so theme attributes like `color_name`/
`size_name` are caught automatically), _plus_ the theme attributes **this
specific item** actually varies on, parsed from its own `relationships`
(`itemVariationAttributes()`) — so a variation member's real `material` or
`size`+`color` is caught even if the schema parse misses it. Opt in with
`--include-identifying` (bare = all; `=a,b` = only the named attributes).

#### 8.2 Shrink-guard for multi-valued attributes

`op: replace` on a multi-valued attribute (e.g. `bullet_point`) replaces the
whole array — the one place a partial draft could delete content. If a draft's
array is **shorter** than the live listing, that attribute is **skipped and
warned**, not shrunk. Override with `--allow-shrink` for deliberate cases. The
comparison baseline is the same live snapshot fetched for the backup, so no
extra API call and no staleness.

#### 8.3 Backup & restore

On every `--apply`, before any patch, the live listing is fetched and written
to `data/{account}/backups/{sku}/{ts}.json` (committed to git). Only touched
SKUs are snapshotted, so the footprint stays small. `restore_listings.php`
replays a chosen backup's attribute values back to Amazon — itself dry-run by
default, itself taking a fresh pre-restore backup, and subject to the same
identifying guard.

```bash
# Restore the latest backup of every SKU (dry-run):
php amazon/scripts/restore_listings.php --account=IGE
# Restore one SKU from a specific backup, and apply:
php amazon/scripts/restore_listings.php --account=IGE --sku=IGE-PENLIGHT --timestamp=2026-07-02-12-00-00 --apply
```

**Fidelity limits:** restore replays _attribute values only_. It does **not**
un-suppress a listing Amazon delisted, undo a catalog-level variation
merge/split, or recover a deleted SKU/offer. SKU-specific commercial data
(price, offer, fulfillment, procurement — the `NON_RESTORABLE` set) is never
replayed. For structural/variation problems, diagnose with Phase 10 first.

#### 8.4 `-NCX` / `-FBA` placeholder templating

`-NCX`/`-FBA` SKUs are placeholders sharing a base SKU's ASIN/product_type/
title, so copying identifying data from the base is safe by definition.
`draft_listings.php --template-placeholders` union-fills each placeholder from
its base SKU's Amazon listing snapshot (**fill-missing only** — never
overwrites what the placeholder already has; never copies offer/fulfillment/
procurement). Entries are tagged `source: base_template`. Writing the copied
identifying data still requires `--include-identifying` at patch time.

#### 8.5 Always-present compliance attributes

`lib/ComplianceAttributes.php` lists attributes that must always be present
before a patch (seed: `california_proposition_65`, `pesticide_marking`). **AI
is barred** from authoring these. Resolution is per-attribute:

- **`california_proposition_65`** → deterministic rule in
  `lib/ComplianceResolvers.php`, evaluated in precedence order: product type in
  `SILICA_PRODUCT_TYPES` (sharpening stones / `KNIFE_SHARPENER`) →
  `chemical_names: ["silica_crystalline_airborne_particles_of"]`; else in the
  maintained `LEAD_PRODUCT_TYPES` list (edged-blade/metal-tool types) →
  `["lead"]`; otherwise → `["bisphenol_a_bpa"]`. Because the rule always
  resolves, Prop 65 never blocks. _Keep both lists current — under-listing lead
  or silica is the compliance miss._
- **`pesticide_marking`** (and any attribute with no resolver) → sourced from
  Usurper (human-verified) only; if absent, the **entire SKU patch is
  hard-blocked** and reported. Override with `--skip-compliance-block`.

"In scope" is refined per attribute type: the resolvable Prop 65 attr is in
scope wherever a schema _defines_ it (it self-resolves); a no-resolver attr is
in scope only where a schema _requires_ it (`schema.required[]`), so it blocks
only products that genuinely need the marking.

### Phase 9 — project drafts to Usurper

**Use case:** persist the AI-authored values back into Usurper so they become
native product data — closing the self-healing loop so future runs don't pay
for AI again.

Reads all `drafts/{sku}.json` and emits a Usurper-compatible CSV. Column
resolution: `source:usurper` entries are skipped by default (already in
Usurper); `source:ai` with a known map entry → that column; `source:ai` with no
map entry → `attr.amazon_{name}` (Usurper creates the custom attribute at
import time).

**Modular titles:** the per-provider `*_ai_{provider}` draft keys (`review_only`)
are skipped; instead the reviewer's chosen `item_name` / `title_differentiation`
from `output/title_decisions.csv` (Phase 6.6, via `Ige\Amazon\Ai\TitleDecisions`)
are projected to their Usurper columns (`item_name` → `attr.title_amazon`,
`title_differentiation` → `attr.amazon_title_differentiation`). No decision = no
title column written.

```bash
php amazon/scripts/project_to_usurper.php --account=IGE
php amazon/scripts/project_to_usurper.php --account=DOWS

# Full attribute refresh (also emit the usurper-sourced values):
php amazon/scripts/project_to_usurper.php --account=IGE --include-usurper

# Single-SKU:
php amazon/scripts/project_to_usurper.php --account=IGE --sku=IGE-PENLIGHT
```

Output: `output/usurper_update_{ts}.csv`. Import it into Usurper. On the next
InventoryExport those `attr.amazon_*` columns come back, and Phase 6
reclassifies the attributes as `fillable`.

### Variation reconciliation (Phase 10)

**Use case:** diagnose broken variation families — the Gear-Aid-style "a
variant lost its theme or joined the wrong parent" symptom — without touching
anything. Read-only, no API calls; works from the on-disk snapshots plus the
Usurper export.

`analyze_variations.php` reconciles each SKU across the three layers Amazon
exposes, which are **not** interchangeable:

- **Intended (Usurper)** — `parent.sku`, `sku_type`, `attr.variation_theme_amazon`.
- **Submitted (Listing)** — `relationships[]` → parent SKUs, `type: VARIATION`, theme.
- **Realized (Catalog)** — read from the _parent_ ASIN's `childAsins` (a
  child's own record often omits the parent pointer).

Each SKU is tagged with zero or more discrepancy categories and _which layer
disagrees_: `orphaned_child`, `wrong_parent`, `unexpected_child`,
`theme_mismatch`, `parent_without_theme`, `dangling_parent`, and
`listing_catalog_divergence`. Each row also carries a summary of the listing's
own `issues[]` (Amazon's compliance verdict). Theme comparison is
token-normalized and heuristic (Usurper themes are free-form).

```bash
php amazon/scripts/analyze_variations.php --account=IGE
php amazon/scripts/analyze_variations.php --account=DOWS
php amazon/scripts/analyze_variations.php --account=IGE --all          # every SKU, not just discrepancies
php amazon/scripts/analyze_variations.php --account=IGE --sku=IGE-XXX  # single SKU
```

Output: `output/variation_analysis_{ts}.csv` (one row per SKU) plus a
`variation_analysis_summary_{ts}.txt` with bucketed category counts and
actionable-vs-backlog tiers. On the first full run it flagged 42/213 SKUs for
IGE and 775/4,618 for DOWS; genuine theme mismatches cluster on `MCN-*` SKUs —
the Gear-Aid family this was built to catch. **Relationship repair is
deliberately out of scope** — it is the single highest-risk write in the system
and will be designed separately once this output is trusted.

### Catalog / listing drift snapshot (Phase 11)

**Use case:** track how the Amazon catalog changes over time. The per-SKU
`input/listings/` and `input/catalog/` snapshots are gitignored — too large and
too noisy (per-fetch timestamps, image CDN hashes) to commit. This phase
distills them into **one small, deterministic, committable digest per account**
at `data/{account}/drift/snapshot.json`, so tracking drift becomes exactly
`git diff` / `git log -p` on that one file. Read-only, no API calls.

`drift_snapshot.php` reads the on-disk snapshots and normalizes them: volatile
keys (`lastUpdatedDate`, `mainImage`/`images` CDN hashes, `salesRanks`, …) are
stripped everywhere; associative arrays are key-sorted; list order is preserved
(bullet-point order is meaningful, so a reorder legitimately shows as drift).

**Deterministic by design:** the snapshot is a pure function of the
volatility-stripped inputs — two runs over identical inputs produce a
byte-identical file, so an empty `git diff` means zero meaningful drift. There
is deliberately no timestamp inside the file; provenance is the git commit.

**Full-fidelity detection in every mode:** each SKU carries a `listing_hash`
computed over its _entire_ normalized listing, so drift in any field is always
caught — at minimum as a changed hash line. An account-wide `digest` (one hash
over every per-SKU hash pair) is the one-glance "did anything change." The mode
only decides how much human-readable content sits next to the hash:

| Mode              | Flag            | Shows                                                                                                             | Size (IGE / DOWS) |
| ----------------- | --------------- | ----------------------------------------------------------------------------------------------------------------- | ----------------- |
| curated (default) | —               | Title, bullets, description, search terms, brand/color/size/material/style, parent*skus, issues — \_what* changed | 540 KB / 12 MB    |
| full              | `--full`        | Every attribute inline                                                                                            | 3.6 MB / 88 MB    |
| hashes-only       | `--hashes-only` | Which SKUs drifted (tripwire)                                                                                     | 76 KB / 1.6 MB    |

The `listing_hash` is identical across all three modes, so switching mode never
changes drift _detection_ — only how much context sits beside the hash.

```bash
php amazon/scripts/drift_snapshot.php --account=IGE
php amazon/scripts/drift_snapshot.php --account=DOWS
php amazon/scripts/drift_snapshot.php --account=DOWS --hashes-only   # slim tripwire for large accounts
php amazon/scripts/drift_snapshot.php --account=IGE --full           # every attribute inline
```

Before overwriting, the run diffs against the prior snapshot and prints an
added / removed / changed SKU summary, so you get the drift report without
needing git. Output: `data/{account}/drift/snapshot.json` (committed) — commit
it to record each drift checkpoint.

> **Cadence note:** DOWS curated is ~12 MB, rewritten on each run; frequent
> snapshots accrue git history. `--hashes-only` is the pressure-release valve
> for high-frequency DOWS tracking.

---

## Output formats reference

| Path                                                       | Contents                                                                                                          |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `input/reports/listings_{ts}.{tsv,json}`                   | Raw TSV + normalized sidecar keyed by `seller-sku`; `asin1` is the SKU→ASIN map.                                  |
| `input/reports/suppressed_{ts}.{tsv,json}`                 | Same shape, suppressed listings.                                                                                  |
| `input/listings/{sku}.json`                                | Raw `ListingsItemsV20210801` response, lossless.                                                                  |
| `input/catalog/{asin}.json`                                | Raw `CatalogItemsV20220401` response, lossless.                                                                   |
| `input/catalog/errors/{asin}.json`                         | `{error, http_status, asin, fetched_at, response}` envelope for non-200s.                                         |
| `input/usurper/*.csv`                                      | Usurper InventoryExport dump (drop here; latest by mtime wins).                                                   |
| `data/schemas/{PRODUCT_TYPE}.json`                         | Raw JSON Schema from Amazon. Committed.                                                                           |
| `data/schemas/_index.json`                                 | `{productType: {fetched_at, version, locale, source_url}}`.                                                       |
| `output/listings_audit.csv`                                | One row per SKU: missing counts, top missing attrs, priority.                                                     |
| `output/listings_gap_fill.csv`                             | One row per SKU/attribute: `fillable`, `usurper_column`, `usurper_value`.                                         |
| `compare/{sku}.json`                                       | Anthropic vs OpenAI `item_name`/`title_differentiation` candidates (Phase 6.5). Committed.                        |
| `output/title_compare.csv`                                 | Flat side-by-side of both providers' title candidates, rebuilt from `compare/`.                                  |
| `output/title_decisions.csv`                               | Editable decision sheet (Phase 6.6): set `*_pick` per row; read by patch (`--include-titles`) + project.         |
| `drafts/{sku}.json`                                        | Authored draft; per-attribute `value`/`is_required`/`source`. Committed.                                          |
| `backups/{sku}/{ts}.json`                                  | Pre-change live `getListingsItem` snapshot. Committed.                                                            |
| `output/patch_results_{ts}.csv`                            | Per-SKU write result: status, submission id, issues.                                                              |
| `output/usurper_update_{ts}.csv`                           | `sku` + one column per AI-authored attribute (`attr.*` / `attr.amazon_*`).                                        |
| `output/variation_analysis_{ts}.csv` + `_summary_{ts}.txt` | Per-SKU variation discrepancies + bucketed summary.                                                               |
| `drift/snapshot.json`                                      | Deterministic per-account drift digest: per-SKU `listing_hash`/`catalog_hash` + account-wide `digest`. Committed. |

---

## Deferred / out of scope

- **CA / MX / BR marketplaces.** All scripts are scoped to US today. The NA
  endpoint covers CA/MX/BR under the same credentials, so it's low-friction
  auth-wise; the open design question is single multi-marketplace
  `getCatalogItem` calls vs parallel per-marketplace directories. Deferred
  until cross-marketplace gap analysis is shown to be worth the refactor.
- **Variation relationship repair.** Phase 10 _diagnoses_; writing corrected
  parentage/themes is the highest-risk write in the system and is designed
  separately once the diagnostic output is trusted.
- **Usurper round-trip for non-`attr.*` fields.** `project_to_usurper.php`
  handles `attr.*` columns. If Usurper's import ever accepts top-level product
  fields (name, description, …), extend the projection — the raw draft JSON is
  lossless, so it's straightforward.
- **`FeedWriter.php` port.** Usurper's 61 KB write-side feed generator is not
  ported; the pipeline writes via `patchListingsItem` instead.

---

## Reference: patterns borrowed from Usurper

`../usurper/app/Services/Inventory/Marketplace/Amazon/` has battle-tested
patterns that translated directly into these standalone scripts:

- **`SellingPartnerApi/BaseApi.php`** — connector lifecycle + per-call retry
  shape (minus the Laravel rate limiter).
- **`OperationIds.php` / `RateLimits.php`** — per-operation burst/decay rates,
  ported into `lib/AmazonOperationIds.php` and `lib/AmazonRateLimits.php` so
  every script paces itself.
- **`{CatalogItems,Listings,ProductTypeDefinitions,Reports}/Api.php`** — exact
  method signatures and DTO types; the scripts call the same connector methods.
- **`CatalogItemListing.php`** — `parseCatalogItemData()` / `parseListingData()`
  as the reference for what to extract from each response.

```

```
