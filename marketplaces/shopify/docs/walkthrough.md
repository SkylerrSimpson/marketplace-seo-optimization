# Walkthrough: one real product through the core pipeline

This traces a single real product — numeric id `8406498541868`, "24pc Deluxe 50 Inch
Aluminum Folding Sluice Box Gold Panning Kit with 30L Backpack" — through the core
metadata pipeline, with the actual commands and actual data at each step. If
`marketplaces/shopify/README.md`'s script tables feel abstract, this is the concrete version.

## Step 1 — audit

```bash
php marketplaces/shopify/scripts/audit_products.php
```
Writes one row per product to `data/output/products_audit.csv`. This product's row
flags a weak SEO description:
```
seo_description: "deluxe expert gold panning kit with 50in sluice box"
```
Thin, all-lowercase, reads like a keyword list rather than a sentence — a fix candidate.

## Step 2 — export the grounding material

```bash
php marketplaces/shopify/scripts/export_descriptions.php   # -> data/input/phase2_input.json
php marketplaces/shopify/scripts/export_image_alts.php     # -> data/input/image_alts.json
php marketplaces/shopify/scripts/export_variants.php       # -> data/input/variants.json
```
These are read-only pulls of the *current* live state — the full body HTML, the current
image alt text, variant option structure. Nothing authored yet; this is just the raw
material an author works from (same role as eBay's `desc_source_pack.jsonl`).

## Step 3 — author the replacement content

Not a script — a human/LLM authoring pass, grounded in what Step 2 exported. The result
goes into `data/drafts/` (source of truth, committed):

```json
// data/drafts/drafts_manual.json
"8406498541868": "Complete 24-piece gold panning kit with a rust-free 50-inch folding aluminum sluice box, gold pans, vials, and tools in a 30L canvas backpack."
```
```json
// data/drafts/drafts_alt.json
"8406498541868": "24-piece deluxe gold panning kit with a 50-inch folding aluminum sluice box, gold pans, and a 30L canvas backpack."
```
Note these are two *different* strings for two different purposes — the SEO description
(longer, sells the product) and the image alt text (shorter, describes the image) — not
the same text copy-pasted into two fields.

## Step 4 — assemble the reviewable sheet

```bash
php marketplaces/shopify/scripts/assemble_output.php
```
Merges the drafts back against the exported inputs, fills any blank `product_type`, and
writes the before/after review sheet:

```
data/output/phase2_output.csv:
  old_seo_description: "deluxe expert gold panning kit with 50in sluice box"
  new_seo_description: "Complete 24-piece gold panning kit with a rust-free 50-inch
                         folding aluminum sluice box, gold pans, vials, and tools in a
                         30L canvas backpack."
  old_image_alt: "deluxe expert gold panning kit with 50in sluice box"
  new_image_alt: "24-piece deluxe gold panning kit with a 50-inch folding aluminum
                  sluice box, gold pans, and a 30L canvas backpack."
  image_alt_changed: 1
  status: ok
```
This is the file a human reviewer actually reads — every product, old vs. new, side by
side. `data/output/phase2_output.json` is the same data in the shape `apply_metadata.php`
actually consumes.

## Step 5 — write it back (dry-run first, always)

```bash
php marketplaces/shopify/scripts/apply_metadata.php                       # dry run — logs what WOULD change
php marketplaces/shopify/scripts/apply_metadata.php --apply --limit 3      # canary — actually writes, first 3 only
php marketplaces/shopify/scripts/apply_metadata.php --apply                # full — idempotent, skips already-correct rows
```
For this product, `--apply` would write the new `seo.description` and image alt via
Shopify's Admin GraphQL API. **Idempotent** means re-running the full `--apply` after
it's already succeeded once does nothing on this row — it reads the live value first and
skips if it already matches.

> **The incident that made this pipeline careful:** an earlier, separate script
> (`apply_seo_titles.php`, writing only `seo.title`) once nulled out every touched
> product's `seo.description`, because Shopify's `SEOInput` mutation **replaces the
> whole object** — omitting a field doesn't leave it alone, it clears it.
> `restore_seo_descriptions.php` was the one-off fix. `apply_metadata.php` avoids this by
> always writing both fields together; any new script touching `SEOInput` must do the same.

## Step 6 — verify it actually took

```bash
php marketplaces/shopify/scripts/verify_applied.php        # re-pulls live catalog, diffs vs. phase2_output.json
php marketplaces/shopify/scripts/validate_storefront.php   # checks the RENDERED public page, not just the Admin API's view
```
`verify_applied.php` catches a write that silently partially failed (some products
updated, some not). `validate_storefront.php` goes one step further and confirms the
change is actually visible in the page's meta tags/JSON-LD — the API can say success
while a cache or template issue still hides the change from Google.

---

## Where this same product might show up in other work-streams

This walkthrough covers the *core* pipeline only. The same product could separately
appear in:
- **GTIN/MPN work** (`gtin_worklist.php` → `apply_mpn_gtin.php`) if its barcode is missing.
- **Google Shopping attributes** (`apply_variant_google_shopping.php`) for
  condition/gender/age_group/size fields.
- **Video SEO** (`audit_product_media.php` → ... → `build_video_watch_pages.php`) if it
  has an embedded product video.

These are separate, independently-runnable work-streams — see `marketplaces/shopify/README.md` for
their own script tables. A product doesn't have to go through all of them.
