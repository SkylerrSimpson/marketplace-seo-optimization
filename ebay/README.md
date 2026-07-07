# eBay — Item Aspects, Descriptions & Images Pipeline

Audits and improves eBay listing data for ASR Outdoor's two seller accounts — **dows**
("DealsOnly", `EBAY_API_*`) and **ige** ("Irongate Enterprises", `EBAY_API_*_IGE`) — and
pushes approved changes back live via the Trading API. Almost every script takes
`--account=dows|ige`; a handful accept `--account=` for both or default to one.

All scripts require `../../lib/bootstrap.php` (autoload + `.env` + path constants) and
read/write under `ebay/data/<account>/`. Run from the repo root:

```bash
php ebay/scripts/<script>.php --account=dows
python3 ebay/scripts/<script>.py
```

## Setup

```bash
composer install               # benmorel/ebay-sdk-php + dvicklund/ebay-oauth-php-client + phpdotenv
cp .env.example .env            # fill in the EBAY_API_* block for dows, and the _IGE block for ige
```

Needs the PHP **`curl`** and **`xml`** extensions (`sudo apt-get install php8.1-curl
php8.1-xml` or your distro's equivalent) — without them, `check_connection.php` and any
script that actually calls eBay will fail (curl to send the request, ext-xml to parse the
Trading API's XML response back). Getting a refresh token the first time needs
`mint_refresh_token.php` (one-time OAuth consent flow — see its own docblock).

Verify the whole chain works before doing anything else:
```bash
php ebay/scripts/check_connection.php --all
```

---

## Pipeline 1 — Item Aspects / Attributes

Backfills and corrects eBay Item Specifics (aspects), with a human reviewer (Ethan)
signing off on every proposed change before it goes live.

| # | Script | Purpose |
|---|--------|---------|
| 0 | `check_connection.php` | Connectivity smoke test — mints tokens, pings Taxonomy + Trading. Read-only. |
| 1 | `export_listings.php` | Enumerates every active listing (Sell Feed `LMS_ACTIVE_INVENTORY_REPORT`) → `listings.json`. |
| 1 | `enrich_listings.php` | Attaches title/category/current specifics to each listing (Browse `getItem`) → `items/{id}.json`. |
| 4 | `fetch_category_aspects.php` | Caches eBay's Taxonomy aspect schema per leaf category (required/recommended, allowed values) → `data/aspects/{catId}.json`. |
| 5 | `audit_listings.php` | Diffs live specifics vs. the category schema → priority-scored gap report + fill worklist. |
| 6 | `fill_aspects.php` | Deterministic proposed-value fill from a Usurper inventory export, constrained to allowed values. |
| 6 | `build_parent_rollup_tasks.py` | For blank *parent* aspects on variation listings, packages all children's Usurper values as evidence for an LLM roll-up judgment. |
| 6 | `merge_parent_fills.py` | Finalizes the parent roll-up: canonicalizes Country of Origin, fills dominant/unanimous child values into review_sheet.csv. |
| — | `build_review_sheet.php` | **Builds the master `review_sheet.csv`** — one row per (listing × aspect): current value, gap fills, LLM fills, everything downstream reads this. |
| — | `ai_review.php --mode=current` | `--tasks`/`--merge`: LLM sanity-check on values already live on eBay. |
| — | `ai_review.php --mode=blanks` | `--tasks`/`--merge`: LLM judgment on whether a blank aspect is genuinely not-applicable (feeds rule #5 below). |
| — | `ai_review.php --mode=deep` | `--tasks`/`--merge`/`--run`: LLM fill (with a certainty score) for gaps nothing deterministic could fill. |
| — | `dedup_answers.php` | Cleans a raw LLM-answers JSONL (last-item-id-wins) before merging. |
| — | `apply_review_rules.php` | Applies Ethan's 5 proposing rules (allowed-value snap, Prop 65, Country default, Mfr Warranty, blank_value) into `proposed_value`. Re-run **after** `build_review_sheet.php` or it gets clobbered. |
| — | `triage_blanks.php` | Buckets every still-blank aspect into a reason category, so the team knows *why* it's blank. |
| — | `split_review_queues.php` | Splits the sheet into a hand-fill queue (blanks worth eyeballing) and an LLM-spotcheck queue (sorted by ascending certainty). |
| — | `plan_next_pass.php` | Plans the next Usurper export batch — which mapped columns aren't exported yet, scored by blank-gap demand. |
| — | `merge_handoff_approvals.php` | Folds Ethan's returned review CSV back into `approved_value` (matched on item_id+sku+normalized aspect; `blank_value` on a live aspect → DELETE). |
| — | `normalize_handoff_units.php` | Unit-spelling normalization ("4 In" → "4 in") on a **returned handoff CSV**, with a **vary-by guard**. |
| — | `normalize_review_sheet_units.php` | Same normalization + guard, applied directly to `review_sheet.csv`'s `proposed_value` (for accounts with no handoff round-trip yet). |
| — | `build_apply_set.php` | **Stage 1 of write-back.** Collapses the sheet into the exact, complete specifics set to push per listing (approved > deterministic > high-certainty LLM), honoring the merge guard. Writes `apply_set.json` + `apply_preview.csv`. Dry-run, no eBay calls. |
| — | `write_canary_test.php` | **Stage 2, canary only.** Pushes a small hand-picked test set (Ethan's edge cases) to eBay via Trading `ReviseItem`, one item at a time. Defaults to `VerifyOnly=true`; `--live` requires re-typing the item id to confirm. |
| — | `apply_aspects.php` | **Stage 2, full write.** Pushes `apply_set.json` to eBay for real — one item (`--item=`), a slice (`--limit=N`), or the whole account. Same `VerifyOnly`-default / `--live` model as the canary script (shares its payload logic via `lib/aspect_writer.php`); a `--live` run over more than one listing additionally requires `--confirm=WRITE`. Logs every Ack to `apply_aspects_run.csv`. |

### The vary-by rule (read this before touching any normalize/merge/write script)

**Never rewrite the value of an aspect that defines a listing's variations** (Size,
Color, sometimes a "hidden" per-child MPN — check `review_sheet.csv`'s `varied_by`
column, or `source=variation` rows). eBay ties a variation's sales history to its exact
value; changing it — even just reformatting the units — silently creates a *different*
variation and orphans that history. Every normalize/merge/write script above already
guards against this; if you write a new one, it must too.

### Value-cardinality gotcha (write-back specifically)

Before splitting any comma-containing value into multiple eBay `Value[]` entries, check
the aspect's real cardinality (`review_sheet.csv`'s `cardinality` column) **and** the
category's actual allowed-values list (`data/aspects/{catId}.json` — not the
`allowed_values` column in review_sheet.csv, which is truncated for long lists). Some
MULTI aspects have individual allowed values that themselves contain a comma (eBay's
Theme picklist has both `"Cartoon"` and `"Cartoon, TV & Movie Characters"` as two
different single entries) — blindly splitting on every comma breaks those. See
`lib/aspect_writer.php`'s `buildSpecifics()` — the shared implementation both
`write_canary_test.php` and `apply_aspects.php` build their `ReviseItem` payload with.

---

## Pipeline 2 — Descriptions

Re-authors every listing's description/title/bullets into one company-standard HTML
template (`ebay/tools/description-generator.html` is the canonical reference the output
must match byte-for-byte in structure), grounded in the listing's own real content —
enriched with Pipeline 1's aspect data once that's complete.

| # | Script | Purpose |
|---|--------|---------|
| 1 | `audit_media.php` | Read-only Browse sweep: images, current description HTML, price per listing → `media/{id}.json`. Shared with Pipeline 3. |
| 2 | `analyze_descriptions.php` | Scores every current description on 8 SEO signals → `description_audit.csv` + a flagged-listing task file. |
| 3 | `extract_description_source.py` | Builds the **grounding source pack** each author works from (title, aspects, narrative, feature bullets, image) — prefers `apply_set.json`'s merged aspects over the raw export. |
| 4 | `split_author_batches.py` | Splits source packs into per-batch input files for authoring agents, skipping already-authored listings. |
| 5 | *(authoring itself)* | Not a script — an LLM authoring pass against `ebay/scripts/AUTHOR_PROMPT.md`'s task spec, batch by batch. See `ebay/docs/reference/pilot_author_style_reference.py` for the style precedent that established tone/format. |
| 6 | `merge_authored_batch.py` | Merges a returned batch (`out_NN.jsonl`) into `desc_authored.jsonl`, keyed by item_id, idempotent. |
| 7 | `build_description_review.php` | **Renders every listing** through the canonical template, diffing old vs. new title/description/bullets/mobile-text → `description_review.csv` (21 columns) + `descriptions/{id}.html`. |
| — | `find_mobile_desc_mismatch.py` | Flags listings where the hidden mobile summary doesn't match the visible body. |
| — | `build_mobile_fix_review.py` | Builds a before/after review sheet for those mismatches, reusing the already-standardized description as the fix. |

---

## Pipeline 3 — Images (audit stage; write stage not started)

| Script | Purpose |
|--------|---------|
| `audit_media.php` | Same read-only sweep as Pipeline 2 — image URLs, host, pixel dimensions. |
| `build_image_review.py` | Turns the media audit into a prioritized remediation worklist (HIGH = self-hosted/non-EPS images, MED = below 800px/no zoom, LOW = below 1600px ideal or <3 images) → `image_review.csv`. |

**Note on image alt text:** eBay's native Picture gallery has no alt-text field at all
(checked the Trading SDK — `PictureDetailsType` is just a bare URL list). The only alt
attribute under our control is the single `<img>` embedded in the description body
(`build_description_review.php`'s `imageAltText()`), which is grounded in the authored
factual paragraph rather than a bare title repeat.

---

## Utilities

| Script | Purpose |
|--------|---------|
| `mint_refresh_token.php` | One-time OAuth authorization-code flow to mint a durable (~18mo) refresh token into `.env`. |
| `lib/EbayClient.php` | Shared, framework-free wrapper around the SDK — per-account credential resolution, token minting, Taxonomy/Trading service factories. Everything above requires it alongside bootstrap.php. |

## Known gaps / open work

- **The full-catalog write hasn't been run yet.** `apply_aspects.php` exists and is
  one-item-live-tested (2026-07-07, item `126454417969` — re-pulled live afterward,
  all 8 changes confirmed correct, nothing else moved), but the actual full run across
  all of `apply_set.json` for an account is still pending.
- **Images pipeline has no write/apply step yet** — `image_review.csv` is the worklist,
  nothing pushes fixes back to eBay yet.
- `ebay/PLAN.md` is the original Week-1 planning doc and is now superseded by this file —
  kept for history, not as current guidance (its Phase 6 write-path recommendation, bulk
  Feed/LMS, turned out not to support Item Specifics at all; Trading `ReviseItem` is what
  actually got built).

## Further reading

- **`ebay/docs/walkthrough.md`** — a single real listing traced through every script in
  both pipelines, with actual commands and actual before/after data. Start here if the
  tables above feel abstract.
- `ebay/docs/review-rules.md`, `ebay/docs/review-guide.md` — the aspects review process in detail.
- `ebay/docs/apply-bridge.md` — the write-back bridge design (Stage 1/2).
- `ebay/docs/description-seo.md` — the descriptions pipeline in detail.
- `ebay/docs/media-audit.md` — the media/image audit in detail.
- `ebay/scripts/AUTHOR_PROMPT.md` — the description-authoring task spec/contract.
