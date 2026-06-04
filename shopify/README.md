# Shopify — Product Metadata Pipeline

Audits Shopify products, drafts improved metadata (SEO description, product type, image
alt), assembles a reviewable sheet, and applies approved changes back to the store.

All scripts require `../../lib/bootstrap.php` (autoload + `.env` + path constants) and read
from / write to `shopify/data/`. Run from the repo root:

```bash
php shopify/scripts/<script>.php
```

## Scripts & run order

| # | Script | Reads | Writes | Network |
|---|--------|-------|--------|---------|
| 0 | `audit_products.php`    | Shopify | `data/output/products_audit.csv` | read-only |
| A | `audit_feed.php`        | Shopify | `data/output/feed_audit.csv` (GTIN/image/availability gaps) | read-only |
| 1 | `export_descriptions.php` | Shopify | `data/input/phase2_input.json`, `data/output/phase2_preview.csv` | read-only |
| 1 | `export_image_alts.php`   | Shopify | `data/input/image_alts.json` (current alt + media id) | read-only |
| 1 | `export_variants.php`     | Shopify | `data/input/variants.json` (parent options/values) | read-only |
| 2 | `assemble_output.php`     | `data/input/*`, `data/drafts/*` | `data/output/phase2_output.{json,csv}` | offline |
| 3 | `apply_metadata.php`      | `data/output/phase2_output.json` | Shopify (seo.description + productType + image alt) | **write** |

## Authored content (source of truth)
- `data/drafts/drafts_manual.json` — `{numeric_id: SEO description}` (199, in-session authored)
- `data/drafts/drafts_alt.json` — `{numeric_id: image alt}` (199)

Edit these, then re-run `assemble_output.php` to regenerate the reviewable output. The
assembler fills product_type for the 23 blanks, adds before/after columns, and ASCII-folds
the whole output.

## The write step (Phase 3)
`apply_metadata.php` is **dry-run by default**:

```bash
php shopify/scripts/apply_metadata.php            # dry run — logs intended changes
php shopify/scripts/apply_metadata.php --apply --limit 3   # canary on 3
php shopify/scripts/apply_metadata.php --apply             # full (idempotent; skips correct rows)
```

It is idempotent (reads current values, skips if already correct), checks `userErrors`,
and backs off on THROTTLED. **Requires a token with `write_products` scope** — the audit
token is read-only and will 403 on `--apply` until re-authorized.

## Rules & docs
- Field rules + AI drafting prompt: `rules/product-metadata-rules.md`
- Strategy / next steps: `../docs/`
