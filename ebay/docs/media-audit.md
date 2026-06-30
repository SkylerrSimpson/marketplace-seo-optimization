# eBay media + listing-content audit (`audit_media.php`)

Read-only Browse `getItem` sweep that captures, per ACTIVE listing, the raw
material for image-quality and description-SEO work. **No eBay writes.** Mirrors
`enrich_listings.php` (same reachable surface) but writes a separate `media/`
snapshot so it never clobbers the Phase-1 aspect snapshots in `items/`.

## What it collects (the 5 asks)
1. **image_count** — primary `image` + `additionalImages`.
2. **description** — the FULL HTML `description` (not just the text). For
   multi-variation listings the group endpoint omits it, so we backfill from one
   child `getItem` (all variations share the same HTML).
3. **price** — `price.value` + `price.currency` (displayed BIN price).
4. **image urls** — every gallery URL, each tagged with:
   - `host` + `is_eps`: EPS / eBay-hosted (`*.ebayimg.com`) vs **self-hosted**
     (any other domain — a quality/availability risk).
   - `width` × `height`: pixel dimensions eBay returns per image (no extra HTTP),
     so we flag images below the **800px** zoom threshold / **1600px** ideal.
5. *(downstream)* SEO description rewrites consume `media/{itemId}.json`.

## Run
```
php ebay/scripts/audit_media.php --account=dows         # full roster (resumable)
php ebay/scripts/audit_media.php --account=ige
php ebay/scripts/audit_media.php --account=dows --ids=ID --refresh   # one listing
```

## Outputs (`ebay/data/{account}/output/`)
- `media/{itemId}.json` — price, images[] (url/w/h/host/is_eps), image_count,
  description (HTML), short_description, revision, is_group, status.
- `media_summary.csv` — one row/listing (price, image_count, eps stats,
  min/max longest-side px, below_zoom_800, below_ideal, desc html/text length).
- `media_images.csv` — one row/image (item_id, position, url, w, h, host, is_eps).

## Results (2026-06-22)
| | listings | images | avg/listing | no image | listings w/ non-EPS img | below 800px zoom | no description |
|---|---|---|---|---|---|---|---|
| DOWS | 1257 | 10,394 | 8.3 | 0 | 84 | 99 | 0 |
| IGE  | 370  | 1,724  | 4.7 | 0 | 16 | 41 | 0 |

**Self-hosted (non-EPS) images** — the main image risk:
- DOWS: 1,010 on `s3.amazonaws.com` + 4 on `d3d71ba2asa5oz.cloudfront.net` (84 listings).
- IGE: 120 on `s3.amazonaws.com` (16 listings).

These render today but aren't in eBay Picture Services: if the bucket/CDN changes
they break, and eBay can't generate zoom/optimized variants from them. Candidate
for re-hosting to EPS (gated like all writes).
