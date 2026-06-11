# Amazon — SP-API Infrastructure

Foundation for auditing the IGE/DOWS Amazon catalog against Amazon's
per-productType requirements, mirroring the Shopify pipeline's shape
(read-only audit/export → author → reviewable output → guarded write) but
adjusted for Amazon's constraints.

> **Status:** Phase 0–4 complete. Phase 5 (audit) is the remaining read-only
> script. The write phase (`putListingsItem` / `patchListingsItem`) waits on
> Usurper's catalog dump and on the authored-content phase that depends on it.

---

## Context

The Shopify pipeline already audits products, drafts SEO fixes, and writes
approved changes back via the Admin API. The Amazon side needs an equivalent
foundation, but the constraints differ:

- **No "list-all" Listings endpoint.** SP-API's Listings Items API is per-SKU
  (`getListingsItem(sku)`). The conventional way to enumerate a seller's
  catalog is the **Reports API** (`GET_MERCHANT_LISTINGS_ALL_DATA`).
- **Schemas are per-productType.** To know what attributes Amazon requires
  for a given listing, we need the **Product Type Definitions API**. Schemas
  don't change often, so we cache them to disk (`amazon/data/schemas/`) for
  manual review and to avoid re-fetching.
- **The Usurper full-catalog CSV doesn't exist yet.** Skyler is building it.
  Until then, Amazon's own Reports API serves as our spine — we treat
  Amazon as the temporary source of truth for "what SKUs do we have?" and
  write per-SKU JSON snapshots to disk for offline analysis.
- **No write phase yet.** All scripts below are read-only. The eventual
  write-back will mirror Shopify's guarded-write pattern (`--apply` flag,
  dry-run default), but only after authored content exists to push.
- **Usurper file format is TBD.** `app/Services/UsurperFileProcessor.php`
  (imports) and `app/Exports/InventoryExport.php` (exports) define
  Usurper's CSV shape — but they aren't symmetric today, and the import
  side doesn't yet accept product-data fields (only quantity). We park
  "emit Usurper-compatible files" until that shape stabilizes, and design
  our per-SKU JSON to be lossless / re-projectable into whatever Usurper
  lands on.

Reuse Usurper's SP-API app credentials (one app, two consumers). Single
marketplace at launch: **US (ATVPDKIKX0DER)**. Start in **sandbox** to
validate wiring, flip to prod via an env flag.

---

## SDK choice

[`jlevers/selling-partner-api`](https://github.com/jlevers/selling-partner-api)
(raw, Saloon-based). This is the same library Usurper consumes (Usurper
wraps it via `highsidelabs/laravel-spapi`, which adds DB-backed credential
storage and Laravel cache). Since this project is plain PHP + dotenv (no
Laravel), we take the raw SDK directly — the
`SellingPartnerApi::seller(...)` connector handles refresh tokens
internally via Saloon's authenticator. Credentials live in `.env` only.

Composer additions:

```
composer require jlevers/selling-partner-api
```

That's the only Amazon dependency. The SDK pulls Saloon + Guzzle
transitively.

---

## Directory layout

Data directories are **account-scoped** (`ige/`, `dows/`). Schemas are
shared across accounts and live outside the account tree.

```
amazon/
├── README.md             ← this file
├── data/
│   ├── {account}/        ← one subtree per seller account (ige, dows)
│   │   ├── input/
│   │   │   ├── reports/      ← listings_{ts}.tsv + .json, suppressed_{ts}.tsv + .json
│   │   │   ├── listings/     ← {sku}.json  per-SKU getListingsItem snapshot
│   │   │   └── catalog/      ← {asin}.json per-ASIN getCatalogItem snapshot
│   │   │       └── errors/   ← {asin}.json structured error envelope for 404s etc.
│   │   ├── drafts/       ← (future) authored content
│   │   └── output/       ← assembled audits / review files
│   └── schemas/          ← cached Product Type Definition schemas (shared)
│       ├── _index.json   ← {productType: {fetched_at, version, locale, source_url}}
│       └── {PRODUCT_TYPE}.json   ← raw schema JSON, one per type
└── scripts/              ← PHP entrypoints (run via `php amazon/scripts/<x>.php`)
```

All data dirs are gitignored except `drafts/` (authored content = source
of truth, mirrors Shopify's convention). `data/schemas/` is **committed** —
small files, high value as a reviewable artifact and as protection
against accidental re-downloads. Per-SKU/per-ASIN JSON in
`input/listings/` and `input/catalog/` is gitignored (regenerable,
potentially large).

---

## Phase plan

### Phase 0 — bootstrap & connectivity ✓

- `jlevers/selling-partner-api` added to `composer.json` and installed.
- `lib/bootstrap.php` defines `AMAZON_ROOT`, `AMAZON_DATA`, `AMAZON_SCHEMAS`
  and an `amazon_paths(string $account)` helper that returns account-scoped
  paths and creates directories on first use:
  ```php
  $paths = amazon_paths('IGE');
  // keys: data, input, reports, listings, catalog, catalog_errors, drafts, output, schemas
  ```
- `.env.example` — Amazon block added (placeholders; copy from Usurper's `.env`).
- `lib/AmazonClient.php` — reads the Amazon env block in its constructor
  and exposes a configured `SellerConnector` via `$client->connector`.
  Also exposes `$client->marketplaceId`, `$client->sellerId`,
  `$client->sandbox`, and a `testConnection()` method. Supports two
  accounts via env suffix convention (`_DOWS`). Every Amazon script does:
  ```php
  $amazon = new AmazonClient('IGE'); // or 'DOWS'
  $paths  = amazon_paths('IGE');
  ```
- `lib/AmazonOperationIds.php` — SP-API operation ID constants, ported
  from Usurper's `OperationIds.php`.
- `lib/AmazonRateLimits.php` — per-operation burst/rate/decaySeconds
  table ported from Usurper's `RateLimits.php`, plus `throttle()` and
  `retryWithBackoff()` helpers. All Phase 2+ scripts pace themselves with
  these.
- **Script 0**: `amazon/scripts/check_connection.php` — calls
  `testConnection()`, prints endpoint/marketplace/seller, exits 0 on
  success.
- All scripts support `--help` and `--account=IGE|DOWS`.

### Phase 1 — catalog export via Reports API ✓

- **Script 1**: `amazon/scripts/export_listings_report.php`.
  - Requests **two reports upfront** so Amazon processes them in parallel:
    `GET_MERCHANT_LISTINGS_ALL_DATA` (all active listings) and
    `GET_MERCHANTS_LISTINGS_FYP_REPORT` (suppressed listings).
  - Idempotency: skips both if today's files already exist; pass `--force`
    to overwrite.
  - Polls each report with 30s→120s exponential backoff (max 20 attempts
    ≈ 15 min). Console shows `attempt N: {STATUS} — sleeping Xs` so
    progress is unambiguous. Exits non-zero on FATAL/CANCELLED.
  - Downloads and optionally GZIP-decompresses each report document.
  - Writes per report:
    - `reports/listings_{timestamp}.tsv` — raw TSV
    - `reports/listings_{timestamp}.json` — normalized sidecar keyed by
      `seller-sku`; fields include `asin1` (the ASIN key for Phase 3+)
    - `reports/suppressed_{timestamp}.tsv` + `.json` — suppressed listings

The resulting `listings_*.json` is our **SKU → ASIN map** (`asin1` field)
for everything else.

### Phase 2 — per-SKU listings snapshot ✓

- **Script 2**: `amazon/scripts/export_listings_items.php`.
  - Uses `searchListingsItems` (paginated, pageSize 20) rather than
    per-SKU `getListingsItem`. `includedData`: attributes, issues,
    summaries, offers, fulfillmentAvailability, procurement,
    relationships, productTypes.
  - **Amazon caps `searchListingsItems` at 1,000 results.** For accounts
    under the cap (IGE: 213 SKUs), paginates normally. For accounts over
    the cap (DOWS: 4,620 SKUs), falls back to date-range chunking —
    splits the catalog into windows of 500 SKUs by `open-date` and issues
    one `searchListingsItems` call per window with `createdAfter`/
    `createdBefore`. Requires Phase 1 report to be present for the
    open-date data.
  - Final fallback: any SKU still missing after all windows is fetched
    individually via `getListingsItem`.
  - Writes `input/listings/{sku}.json` — raw API response JSON, lossless.
  - Idempotent: skips SKUs whose file already exists unless `--force`.
  - `--limit=N` for canary runs.

### Phase 3 — per-ASIN catalog snapshot ✓

- **Script 3**: `amazon/scripts/export_catalog_items.php`.
  - Reads the latest `listings_*.json` report sidecar, extracts unique
    ASINs (`asin1` field). IGE: 179 unique ASINs; DOWS: 3,943.
  - `catalogItemsV20220401()->getCatalogItem(asin, [US], includedData:
    ['attributes','classifications','identifiers','productTypes',
    'relationships','salesRanks','summaries','dimensions','images'])`.
  - Successes → `input/catalog/{asin}.json` (raw API response, lossless).
  - Errors (404s, etc.) → `input/catalog/errors/{asin}.json` — a
    structured JSON envelope (`error`, `http_status`, `asin`,
    `fetched_at`, `response`) rather than a dropped record. IGE: 5
    errors (2.8%); DOWS: 188 errors (4.8%) — all confirmed 404s for
    delisted/discontinued products.
  - On `--force`, if an ASIN flips outcome (success↔error), the stale
    file in the opposing directory is deleted automatically.
  - Idempotency checks both `catalog/` and `catalog/errors/` so 404'd
    ASINs are not re-fetched on subsequent runs unless `--force`.
  - `--limit=N` for canary runs.

Why both Listings Items and Catalog Items? Listings Items returns **what
_we_ told Amazon** (our submitted attributes + Amazon's validation
issues). Catalog Items returns **what Amazon's catalog actually shows**
(the merged view with browse-tree classifications, sales ranks, the
public attribute set). Gap analysis needs both.

### Phase 4 — product type schema cache ✓

- **Script 4**: `amazon/scripts/fetch_product_type_schemas.php`.
  - Dynamically discovers all account listing directories under
    `amazon/data/*/input/listings/`, collecting distinct
    `summaries[*].productType` values. Found **318 distinct product
    types** across IGE + DOWS combined.
  - Uses IGE credentials (schemas are account-agnostic; IGE holds the
    developer app).
  - For each productType not already cached:
    `productTypeDefinitionsV20200901()->getDefinitionsProductType(
    productType, [US], sellerId, requirements: 'LISTING',
    requirementsEnforced: 'ENFORCED')`. The response contains a URL to
    the actual JSON Schema document, which is fetched separately and
    saved to `amazon/data/schemas/{PRODUCT_TYPE}.json`.
  - Updates `amazon/data/schemas/_index.json` after each schema with
    `{fetched_at, version, locale, source_url}`, so partial runs are
    safe to resume.
  - Re-running is a no-op for already-cached types unless `--force`.

Result: `amazon/data/schemas/` is the reviewable, committed reference
for what Amazon requires across all 318 product types we sell.

### Phase 5 — audit (read-only)

- **Script 5**: `amazon/scripts/audit_listings.php` — the Amazon
  counterpart to `shopify/scripts/audit_products.php`.
  - For each SKU, loads `{account}/input/listings/{sku}.json`,
    `{account}/input/catalog/{asin}.json`, and
    `data/schemas/{productType}.json`.
  - Compares the listing's `attributes` against the schema's
    `required` + `recommended` attribute sets.
  - Emits `{account}/output/listings_audit.csv` with one row per
    SKU: columns for ASIN, productType, missing-required count,
    missing-recommended count, top missing attribute names, current
    Amazon issues count, priority score (mirror Shopify's
    weighted-flag pattern).
  - Read-only, no API calls (everything works from disk).

Phase 5 is where we **stop for this iteration**. The next phases —
authored content (drafts), assembly, and guarded write — wait on
Skyler's Usurper catalog dump so we can correlate Amazon's gaps against
our own master data.

---

## Patterns to mirror from Usurper

`../usurper/app/Services/Inventory/Marketplace/Amazon/` has battle-tested
patterns that translate directly to standalone PHP scripts:

- **`SellingPartnerApi/BaseApi.php`** — connector lifecycle + per-call
  retry shape. We don't need the Laravel rate limiter, but the
  attempt/sleep/retry skeleton transfers.
- **`SellingPartnerApi/OperationIds.php`** & **`RateLimits.php`** —
  Amazon's per-operation burst/decay rates. Worth porting verbatim into
  `lib/amazon_rate_limits.php` so each script can pace itself correctly.
- **`SellingPartnerApi/{CatalogItems,Listings,ProductTypeDefinitions,Reports}/Api.php`** —
  exact method signatures and DTO types per operation. Our scripts call
  the same connector methods directly; these files are the cheat sheet.
- **`CatalogItemListing.php`** — `parseCatalogItemData()` and
  `parseListingData()` show what Usurper extracts from each response.
  Useful reference when normalizing the per-SKU/per-ASIN JSON for
  downstream scripts (browse-tree flattening etc.).

We do **not** port `FeedWriter.php` yet (61KB; it's the write-side feed
generator). That comes in the future write phase.

---

## Output formats (until Usurper conformance is decided)

- **`{account}/input/listings/{sku}.json`** — raw `ListingsItemsV20210801`
  response body, lossless. Re-project from this for any derived view.
- **`{account}/input/catalog/{asin}.json`** — raw `CatalogItemsV20220401`
  response body, lossless.
- **`{account}/input/catalog/errors/{asin}.json`** — structured error
  envelope for ASINs that returned a non-200 from the Catalog API:
  `{error: true, http_status, asin, fetched_at, response: {errors: [...]}}`.
- **`{account}/input/reports/listings_{ts}.{tsv,json}`** — raw TSV from
  Amazon plus a normalized JSON sidecar keyed by `seller-sku`. The sidecar
  `asin1` field is the SKU→ASIN map consumed by Phase 3.
- **`{account}/input/reports/suppressed_{ts}.{tsv,json}`** — same shape
  for suppressed listings.
- **`data/schemas/{PRODUCT_TYPE}.json`** — raw JSON Schema document from
  Amazon's schema URL. Committed to git.
- **`data/schemas/_index.json`** — `{productType: {fetched_at, version, locale, source_url}}`.
- **`{account}/output/listings_audit.csv`** — Shopify-style audit CSV,
  one row per SKU.

When Usurper's `UsurperFileProcessor` / `InventoryExport` settle on a
product-data CSV shape, we add a `amazon/scripts/project_to_usurper.php`
that re-projects from the per-SKU JSON. The raw JSON is the durable
artifact; the Usurper CSV is a regenerable projection.

---

## Critical files to create

| Path                                            | Purpose                                                     |
| ----------------------------------------------- | ----------------------------------------------------------- |
| `composer.json`                                 | add `jlevers/selling-partner-api` ✓                         |
| `.env.example`                                  | add Amazon credential block ✓                               |
| `lib/bootstrap.php`                             | add `AMAZON_*` path constants ✓                             |
| `lib/AmazonClient.php`                          | `SellerConnector` + `testConnection()` ✓                    |
| `lib/AmazonOperationIds.php`                    | SP-API operation ID constants ✓                             |
| `lib/AmazonRateLimits.php`                      | per-operation rate table + `throttle()` helper ✓            |
| `amazon/scripts/check_connection.php`           | Phase 0 ✓                                                   |
| `amazon/scripts/export_listings_report.php`     | Phase 1 ✓                                                   |
| `amazon/scripts/export_listings_items.php`      | Phase 2 ✓                                                   |
| `amazon/scripts/export_catalog_items.php`       | Phase 3 ✓                                                   |
| `amazon/scripts/fetch_product_type_schemas.php` | Phase 4 ✓                                                   |
| `amazon/scripts/audit_listings.php`             | Phase 5                                                     |
| `.gitignore`                                    | ignore `amazon/data/input/{reports,listings,catalog}/`      |

---

## Verification

After each phase, the smoke test is a single command:

```bash
composer install
cp .env.example .env    # fill in the Amazon block from Usurper's .env

# Phase 0 — connectivity probe
php amazon/scripts/check_connection.php --account=IGE

# Phase 1 — listings + suppressed reports (both accounts)
php amazon/scripts/export_listings_report.php --account=IGE
php amazon/scripts/export_listings_report.php --account=DOWS

# Phase 2 — per-SKU snapshot (canary first, then full)
php amazon/scripts/export_listings_items.php --account=IGE --limit=5
php amazon/scripts/export_listings_items.php --account=IGE
php amazon/scripts/export_listings_items.php --account=DOWS   # uses date-range chunking (>1000 SKUs)

# Phase 3 — per-ASIN catalog snapshot
php amazon/scripts/export_catalog_items.php --account=IGE --limit=5
php amazon/scripts/export_catalog_items.php --account=IGE
php amazon/scripts/export_catalog_items.php --account=DOWS

# Phase 4 — schema cache (scans all accounts automatically)
php amazon/scripts/fetch_product_type_schemas.php

# Phase 5 — audit CSV
php amazon/scripts/audit_listings.php --account=IGE
php amazon/scripts/audit_listings.php --account=DOWS
```

Every script defaults to **production** credentials as configured in
`.env` (`AMAZON_SPAPI_SANDBOX=false`). All scripts through Phase 5 are
read-only, so prod is safe to run end-to-end.

Acceptance:

- `amazon/data/{account}/input/reports/` contains `listings_*.tsv` and
  `suppressed_*.tsv`.
- `amazon/data/{account}/input/listings/` has one JSON per SKU.
- `amazon/data/{account}/input/catalog/` has one JSON per unique ASIN;
  404s are in `catalog/errors/` with a structured error envelope.
- `amazon/data/schemas/` has 318 schema files + a populated `_index.json`.
- `amazon/data/{account}/output/listings_audit.csv` exists and is sorted
  by priority.

---

## Out of scope (deferred)

- **Authored drafts / content generation.** Waits on Usurper's full
  catalog CSV so we know which gaps are ours to fill vs. Amazon-side
  data quality issues.
- **Write-back to Amazon** (`putListingsItem` / `patchListingsItem`).
  Waits on drafts. When we do build it, mirror Shopify's
  `--apply` / dry-run-default / idempotent / 429-backoff pattern.
- **CA / MX / BR marketplaces.** All scripts are scoped to US
  (`ATVPDKIKX0DER`) today. The NA SP-API endpoint covers US, CA
  (`A2EUQ1WTGCTBG2`), MX, and BR under the same credentials, so adding
  them is low-friction auth-wise. The design question is whether to
  issue a single `getCatalogItem` call with multiple `marketplaceIds`
  (cheaper on quota; response embeds per-marketplace sections in one
  file) or run a separate per-marketplace fetch into parallel
  directories (`catalog/us/`, `catalog/ca/`). The combined-call
  approach saves quota but requires more nuanced response parsing to
  differentiate US vs. CA data. Deferred until we know whether
  cross-marketplace gap analysis is worth the refactor.
- **Usurper-format CSV emitter.** Waits on `UsurperFileProcessor` /
  `InventoryExport` symmetry. Raw JSON snapshots are designed to be
  lossless so the projection is straightforward when the shape lands.
