# Shopify — Product Metadata Pipeline

Audits Shopify products, drafts improved metadata (SEO description, product type, image
alt), assembles a reviewable sheet, and applies approved changes back to the store. Also
covers several other work-streams that grew alongside the core pipeline: collections,
GTIN/MPN/Google Shopping attributes, an accessibility alt-text sweep, nav/taxonomy
utilities, GSC/CTR analysis, and a full video/YouTube SEO pipeline.

All scripts require `../../lib/bootstrap.php` (autoload + `.env` + path constants) and read
from / write to `shopify/data/`. Run from the repo root:

```bash
php shopify/scripts/<script>.php
```

---

## Core pipeline — the reference implementation

New marketplaces (eBay, Walmart) should mirror this shape: read-only audit/export →
author/assemble → reviewable output → guarded write.

| # | Script | Reads | Writes | Network |
|---|--------|-------|--------|---------|
| 0 | `audit_products.php`    | Shopify | `data/output/products_audit.csv` | read-only |
| A | `audit_feed.php`        | Shopify | `data/output/feed_audit.csv` (GTIN/image/availability gaps) | read-only |
| 1 | `export_descriptions.php` | Shopify | `data/input/phase2_input.json`, `data/output/phase2_preview.csv` | read-only |
| 1 | `export_image_alts.php`   | Shopify | `data/input/image_alts.json` (current alt + media id) | read-only |
| 1 | `export_variants.php`     | Shopify | `data/input/variants.json` (parent options/values) | read-only |
| 2 | `assemble_output.php`     | `data/input/*`, `data/drafts/*` | `data/output/phase2_output.{json,csv}` | offline |
| 3 | `apply_metadata.php`      | `data/output/phase2_output.json` | Shopify (seo.description + productType + image alt) | **write** |
| 4 | `verify_applied.php`      | Shopify + `phase2_output.json` | diff report (stdout/CSV) | read-only |
| 4 | `validate_storefront.php` | live storefront HTML | stdout | read-only |

### Authored content (source of truth)
- `data/drafts/drafts_manual.json` — `{numeric_id: SEO description}` (199, in-session authored)
- `data/drafts/drafts_alt.json` — `{numeric_id: image alt}` (199)

Edit these, then re-run `assemble_output.php` to regenerate the reviewable output. The
assembler fills product_type for the 23 blanks, adds before/after columns, and ASCII-folds
the whole output.

### The write step
`apply_metadata.php` is **dry-run by default**:

```bash
php shopify/scripts/apply_metadata.php            # dry run — logs intended changes
php shopify/scripts/apply_metadata.php --apply --limit 3   # canary on 3
php shopify/scripts/apply_metadata.php --apply             # full (idempotent; skips correct rows)
```

It is idempotent (reads current values, skips if already correct), checks `userErrors`,
and backs off on THROTTLED. **Requires a token with `write_products` scope** — the audit
token is read-only and will 403 on `--apply` until re-authorized. Re-mint/re-authorize
with `oauth_mint.php` (below) whenever the app's scopes change.

### After a write: verify it actually took
`verify_applied.php` re-pulls the live catalog and diffs it against what
`phase2_output.json` intended to write — catches silent partial failures.
`validate_storefront.php` goes one step further and checks the **rendered public page**
(meta tags, OpenGraph, JSON-LD, alt text) actually reflects the change, not just the
Admin API's view of it.

> **Known incident, worth knowing about:** Shopify's `SEOInput` mutation **replaces the
> whole object** — if you write `title` without `description`, it nulls the existing
> description out. `apply_seo_titles.php` hit this; `restore_seo_descriptions.php` is the
> one-off fix. Any future script writing `SEOInput` must always send both fields even if
> only one changed.

---

## Collections — full mirror of the product pipeline

| Script | Purpose |
|--------|---------|
| `audit_collections.php` | Gap audit for collection SEO metadata → `data/output/collections_audit.csv` |
| `export_collection_products.php` | Pulls each collection's current body + member products (grounding for drafting) → `data/output/collection_products.json` |
| `apply_collection_metadata.php` | Writes seo.title/description for collections from `data/output/collections_phase2.json` |

## GTIN / MPN / Google Shopping attributes

The corrected, current versions — the field is written at the **variant** level, which is
what the Shopify feed actually reads from (an earlier product-level attempt was wrong and
has been removed):

| Script | Purpose |
|--------|---------|
| `gtin_worklist.php` | Lists every variant's barcode status, builds the missing-GTIN worklist |
| `apply_mpn_gtin.php` | Authoritative GTIN-14 + MPN back-fill from the reviewed worklist |
| `apply_product_mpn.php` | Mirrors variant MPN up to the product-level `mpn` metafield (single-variant products only) |
| `apply_variant_google_shopping.php` | Writes variant-level `condition`/`gender`/`age_group`/`size_system`/`size_type` |
| `apply_google_shopping.php` | Writes product-level `google_product_category` (the one field that's genuinely product-level, not variant-level) |

## Accessibility / alt-text sweep

Broader than the 199-product core pipeline's alt-text step — covers blog articles, product
description bodies, and video media too:

| Script | Purpose |
|--------|---------|
| `apply_image_alts.php` | Writes authored alt text from `data/drafts/image_alts.json` |
| `apply_blog_image_alts.php` | Fills missing alt on `<img>` tags inside blog article bodies |
| `apply_product_desc_image_alts.php` | Fills missing alt on `<img>` tags inside product description HTML |
| `apply_product_video_alts.php` | Fills missing alt on product VIDEO/EXTERNAL_VIDEO media |

## Nav / taxonomy utilities

| Script | Purpose |
|--------|---------|
| `apply_nav_tags.php` | Applies `nav-*` tags to products so Smart collections can drive multi-child nav menus |
| `apply_nav_collection_seo.php` | One-off: SEO title/description for 4 hardcoded aggregate nav collections |

## GSC / CTR analysis

| Script | Purpose |
|--------|---------|
| `analyze_gsc.py` | Analyzes Google Search Console export CSVs, computes CTR-opportunity worklists vs. an expected-CTR curve |
| `apply_ctr_seo.php` | One-off: hand-reviewed SEO title/description changes for specific pages, sourced from the GSC analysis |

## Review / QA utilities

| Script | Purpose |
|--------|---------|
| `count_review_coverage.php` | Counts products with vs. without `reviews.rating_count` |
| `export_review_csv.php` | Per-variant CSV of every SEO/Google-Shopping field, straight from the live store |
| `apply_thin_descriptions.php` | One-off: hand-written prose intro prepended to 10 hardcoded thin/spec-only descriptions |

## Setup utility

| Script | Purpose |
|--------|---------|
| `oauth_mint.php` | One-shot OAuth flow to mint/re-mint `ADMIN_API_TOKEN` with the app's current scopes — needed whenever scopes change (e.g. adding `write_products`) |

---

## Video / YouTube SEO pipeline

A fully separate, self-contained pipeline: audits which product/blog videos are
self-hosted vs. YouTube-embedded, builds a match/replacement plan, pulls real YouTube
metadata, and generates indexable "watch page" articles. See
**`../docs/video-indexing-runbook.md`** for the full narrative — this table is the script
reference:

| # | Script | Purpose |
|---|--------|---------|
| 1 | `audit_product_media.php` | Scans every product's media for self-hosted video vs. YouTube embed → `product_video_inventory.csv` |
| 1 | `audit_blog_media.php` | Same scan for blog article bodies → `blog_video_inventory.csv` |
| 2 | `build_master_video_audit.py` | Combines product/blog/page video inventories, flags indexability → `master_video_audit.csv` |
| 2 | `build_video_map.py` | Builds a video→product/collection/blog match proposal (scrapes YouTube watch pages for date/duration) → `video_product_map.csv` |
| 2 | `build_mp4_worklist.py` | Matches self-hosted MP4 products to their YouTube counterpart → `mp4_replacement_worklist.csv` |
| 3 | `list_product_videos.php` | Enumerates every EXTERNAL_VIDEO on products with a YouTube ID → `data/drafts/video_master_list.csv` |
| 3 | `pull_youtube_meta.php` | Pulls title/description/upload date/duration from the YouTube Data API v3 |
| 4 | `build_video_review_csv.php` | Merges authored watch-page copy with pulled YouTube metadata, flags length issues |
| 5 | `build_video_watch_pages.php` | **Writes to Shopify** — builds a "Videos" blog hub + one watch-page article per video |
| — | `fix_video_articles.php` | One-off remediation: back-dates watch-page `publishDate` to the real upload date, swaps thumbnail to `maxresdefault` |
| — | `apply_video_viewcounts.php` | Rerunnable maintenance: pulls real YouTube view counts into each watch-page's `custom.view_count` |
| — | `verify_videoobject_live.py` | Fetches live product pages, validates the deployed `VideoObject` JSON-LD |

---

## Rules & docs
- Field rules + AI drafting prompt: `rules/product-metadata-rules.md`
- Strategy / next steps / video runbook: `../docs/`
