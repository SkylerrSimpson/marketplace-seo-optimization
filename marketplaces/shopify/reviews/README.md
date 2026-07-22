# Group A — Reviews (fixes GSC `aggregateRating` + `review`)

Goal: add review structured data (the one genuinely missing signal). Installing a
reviews app gives the *container*; seeding real review volume is what makes
`aggregateRating`/`review` render. Recommended app: **Judge.me** (free plan emits
the schema + syncs ratings to Google).

## Files here
- `judgeme_import_template.csv` — starter import file (Judge.me columns + 2 example rows).
- `../data/output/product_handle_reference.csv` — all 199 products: product_id, **product_handle**, title, skus.
  Use this to map marketplace reviews (which key off SKU/ASIN) to the correct Shopify
  `product_handle` (reviews attach to products by handle).

## Steps
1. **Install Judge.me** (Shopify App Store → "Judge.me Product Reviews" → Add app). Accept
   the automatic widget placement on product pages.
2. **Enable Google integration:** Judge.me → Settings → Integrations → Google Shopping /
   rich snippets = ON (this is what emits aggregateRating + syncs to Google).
3. **Seed reviews (the critical step):**
   - Export your existing reviews from Amazon / eBay / Walmart (CSV).
   - In Judge.me, download THEIR official import template (Judge.me → Settings →
     Import/Export → Import reviews → sample CSV) — use their exact headers (they validate
     against their own format; the file here is a guide/fallback).
   - Map each review to the right `product_handle` using `product_handle_reference.csv`
     (match by SKU → handle).
   - Upload to Judge.me.
4. **Turn on review-request emails** (Judge.me → Settings → Review requests; integrates with
   the existing Klaviyo) so new orders keep generating reviews.
5. **Verify (Claude):** once live with reviews, re-scan product pages to confirm
   `aggregateRating` + `review` render in the Product JSON-LD and flow to the feed.

## Notes
- Theme already has dormant Okendo hooks (output `null`); they don't conflict, ignore them.
  Optional cleanup later if not using Okendo.
- If you later want aggregateRating inside the custom `product-structured-data.liquid`
  (Group C) too, add a Judge.me Liquid `aggregateRating` block — but Judge.me's own widget
  already injects valid review schema, so this is optional.
