# ASR Outdoor — Video Indexing Runbook

**Goal:** get ASR's product videos *indexed* by Google (video thumbnail in search +
Videos tab + AI grounding), and standardize all product video onto **YouTube embeds**
(no Shopify-hosted MP4s).

**Store admin slug:** `e2ab17`  ·  **Storefront:** https://asroutdoor.com

---

## Current state (as of 2026-06-08)

Catalog-wide media audit (`shopify/scripts/audit_product_media.php`, read-only →
`shopify/data/output/product_video_inventory.csv`):

- (Original baseline) 13 self-hosted MP4s, 0 external embeds, 185 no video.
- **Post-swap (2026-06-09 re-audit):** **14 products** have a YouTube embed, 1 still has
  both MP4+YouTube (delete the leftover MP4), 1 still MP4-only, 182 no video.
- `VideoObject` structured data: **now emitted by the Phase 2 snippet** (pending deploy).

YouTube channel: **https://www.youtube.com/@asr_outdoor** — 17 listed videos pulled
(titles/dates/durations in `shopify/data/output/video_product_map.csv`). The 5 MP4s
with no match in the 17 are from **YouTube Shorts** (not listed long-form videos).

Approach decided: **hybrid VideoObject snippet** — real upload date/duration for the
known videos (scraped), product publish date as automatic fallback for everything else.
So the existing library gets exact data and any future embed works automatically.

---

## Phase 1A — Swap the 13 MP4s → YouTube embeds  *(owner: you)*

For each product: **Products** (https://admin.shopify.com/store/e2ab17/products) →
open product → **Media**: delete the MP4 → **Add media → Add media from URL** → paste
the YouTube link → **Save**.

| # | Product page | YouTube link |
|---|---|---|
| 1 | https://asroutdoor.com/products/50-aluminum-folding-sluice-box-gold-prospecting-equipment | https://youtu.be/OdcvlszXOH0 |
| 2 | https://asroutdoor.com/products/pocket-sluice-box-for-gold-panning-3-riffle-tpr-rubber-groove-mat-12 | https://youtu.be/3KRzsc-stEE |
| 3 | https://asroutdoor.com/products/gold-mining-compact-magnetic-prospectors-pick-axe | https://youtu.be/Xepf8uTnR7w |
| 4 | https://asroutdoor.com/products/12pc-break-your-own-geodes-kit-with-sledge-hammer-and-safety-goggles-assorted-size-geodes | https://youtu.be/KLobXEEdf1Q |
| 5 | https://asroutdoor.com/products/5lb-rough-brazilian-gemstone-paydirt-bag-geology-science-kit | https://youtu.be/t9Fc9uBs24Y |
| 6 | https://asroutdoor.com/products/5lb-genuine-tumbled-rocks-and-gemstone-paydirt-geology-science-kit-14pc | https://youtu.be/t9Fc9uBs24Y |
| 7 | https://asroutdoor.com/products/gold-panning-aluminum-mini-sluice-box-with-rubber-matting-12-inch | https://youtu.be/I52rzwyfliE  *(verify 12" vs 34")* |
| 8 | https://asroutdoor.com/products/aluminum-sluice-box-with-rubber-tpr-riffle-matting-gold-prospecting-equipment-34-inch | https://youtu.be/vW6hZTmYAqc  *(verify 34" vs 12")* |
| 9 | https://asroutdoor.com/products/rock-pick-hammer | *(your Short)* |
| 10 | https://asroutdoor.com/products/15pc-complete-backpack-gold-panning-kit-with-mini-sluice-box-and-3lb-gold-bobby-bo-paydirt-aluminum-or-rubber | *(your Short)* |
| 11 | https://asroutdoor.com/products/13pc-geology-rock-hounding-kit-with-mining-tools-and-deluxe-carry-bag | *(your Short)* |
| 12 | https://asroutdoor.com/products/3pc-collapsible-camping-lantern-assorted-colors | *(your Short)* |
| 13 | https://asroutdoor.com/products/first-aid-kit-in-plastic-waterproof-case-emergency-supplies-survival-gear-50pc | *(your Short)* |

✅ Done when all 13 show a YouTube embed (not MP4) in their gallery.

### All 17 listed YouTube videos (pick-from reference)
```
t9Fc9uBs24Y  https://youtu.be/t9Fc9uBs24Y  Gemstone Paydirt Kit
KLobXEEdf1Q  https://youtu.be/KLobXEEdf1Q  12pc Break Your Own Geodes Kit (unboxing)
ae63J6_Z-GA  https://youtu.be/ae63J6_Z-GA  Guaranteed Paydirt Bags Showcase
D0Sch7DMcHM  https://youtu.be/D0Sch7DMcHM  Best Shovels & Picks
Xepf8uTnR7w  https://youtu.be/Xepf8uTnR7w  Top Pickaxe
F3nU7PYk2CU  https://youtu.be/F3nU7PYk2CU  30" Plastic Sluice Box Set Up
o6ToaVuqtZk  https://youtu.be/o6ToaVuqtZk  Benefits of Rubber Riffle Matting
vW6hZTmYAqc  https://youtu.be/vW6hZTmYAqc  34" Aluminum Rubber Matting Sluice Box
OdcvlszXOH0  https://youtu.be/OdcvlszXOH0  50" Sluice Box Set Up
UxDOZeiHAes  https://youtu.be/UxDOZeiHAes  Sluice Box Basics & Matting Recovery
GRGKibzHhNQ  https://youtu.be/GRGKibzHhNQ  13" Bucket Classifier Screen (how to)
BX_OnDzxXRA  https://youtu.be/BX_OnDzxXRA  Mini 6" Classifier Screens (how to)
xCIlBiVK-hM  https://youtu.be/xCIlBiVK-hM  Bobby Bo 6pc Paydirt Beginner Kit
5ZUFmtFPgdE  https://youtu.be/5ZUFmtFPgdE  How to Choose the Correct Gold Pan Type
pXoZAJsz4qo  https://youtu.be/pXoZAJsz4qo  How to Season Your Gold Pans
3KRzsc-stEE  https://youtu.be/3KRzsc-stEE  Mini Rubber Pocket Sluice Box (how to)
I52rzwyfliE  https://youtu.be/I52rzwyfliE  12" Aluminum Sluice Box (how to)
```

---

## Phase 1B — Coverage audit: embed videos on products that have NONE  *(owner: you)*

After the 13 swaps, close the gaps — the **185 products with no video** that *should*
have one based on the full YouTube library (long-form **and Shorts**, including videos
past the first 17 that were captured).

Prep (read-only, Claude can generate on request):
1. **Pull the COMPLETE channel** (page past the first 17 + list Shorts) so the audit is
   against the whole library, not a partial list.
2. **Coverage-gap worklist** — cross-reference `video_product_map.csv` (videos → likely
   product) against `product_video_inventory.csv` (which products have no video) → a
   checklist of "product with no video × matching YouTube video" to work from.

Then for each gap: embed the YouTube video on that product (same as Phase 1A step 3) —
and/or place how-to videos into **blog guides** (Online Store → Blog posts).

> Note: the Phase 2 snippet emits `VideoObject` for **every** embedded video, so anything
> you add here is covered automatically — no extra markup work per product.

✅ Done when every product that has a matching YouTube video has it embedded.

---

## Phase 2 — Build the hybrid `VideoObject` snippet  *(owner: Claude)* ✅ DONE 2026-06-09

Covers **all** embedded videos — the swaps (Phase 1A) *and* the new adds (Phase 1B).
Delivered: `shopify/theme/product-structured-data.liquid` now appends a `VideoObject`
block that loops `product.media`, filters `media_type == 'external_video'` + `host == 'youtube'`,
and emits one VideoObject per video:
- `name` = product title, `description` = product description (grounded, no upkeep).
- `thumbnailUrl` = `https://i.ytimg.com/vi/<external_id>/hqdefault.jpg`,
  `embedUrl` = `https://www.youtube.com/embed/<external_id>` — auto from the embed.
- **Hybrid date/duration:** a Liquid `case media.external_id` carries real scraped
  uploadDate+duration for the **11 currently-embedded videos**; anything else falls back
  to `product.published_at` (duration omitted). To give a future video exact data, add one
  `when '<videoID>'` line — the only maintenance this block ever needs.

JSON validity of both render paths (with-duration / fallback no-duration) verified.

Required/recommended VideoObject fields per Google:
https://developers.google.com/search/docs/appearance/structured-data/video

✅ Done — snippet delivered. Next: deploy (Phase 3).

---

## Phase 3 — Deploy the snippet  *(owner: you)*

1. **Themes:** https://admin.shopify.com/store/e2ab17/themes → live theme → **⋯ → Edit code**.
2. Open `snippets/product-structured-data.liquid` → replace all → **Save**.
3. *(Optional safety)* duplicate the theme first, preview, then publish.

✅ Done when saved on the live (or previewed) theme.

---

## Phase 4 — Validate the markup  *(owner: you)*

1. **Rich Results Test:** https://search.google.com/test/rich-results
2. Paste a video product URL, e.g.
   `https://asroutdoor.com/products/12pc-break-your-own-geodes-kit-with-sledge-hammer-and-safety-goggles-assorted-size-geodes`
3. Expect a **Video** item, **0 errors** (a `duration` warning on fallback videos is OK).
4. *(Optional)* Schema validator: https://validator.schema.org/

✅ Done when Rich Results Test shows the Video with no errors.

---

## Phase 5 — Request indexing & monitor  *(owner: you)*

1. **Search Console:** https://search.google.com/search-console → **Video indexing** report.
2. **URL Inspection** on a couple of key video URLs → **Request Indexing**.
3. Monitor the Video indexing report over the next re-crawl (days → weeks; same crawl lag
   as other GSC fixes).

✅ Done when pages appear under "Video is indexed."

---

## Phase 6 — Future videos  *(set & forget)*

1. Make the YouTube video.
2. Embed it on the product (Phase 1, step 3).
3. Nothing else — snippet auto-emits `VideoObject` (uploadDate = product publish date).
4. *(Optional)* For an important video, send Claude the URL to add exact date/duration to
   the lookup.

---

## Owner summary
| Phase | Owner |
|---|---|
| 1A. Swap 13 MP4s → YouTube | You |
| 1B. Coverage audit — embed videos on products that have none | You (Claude preps the gap worklist) |
| 2. Build hybrid snippet (covers 1A + 1B) | Claude |
| 3. Deploy snippet | You |
| 4. Validate (Rich Results Test) | You |
| 5. Request indexing + monitor (GSC) | You |
| 6. Future videos | You (auto) |

> Sequencing tip: you can do Phase 1A and 1B in either order (or together) — both are just
> "embed YouTube videos on products." The snippet (Phase 2) only needs to be built/deployed
> once and will cover everything embedded by then plus anything added later.

## Related files
- `shopify/scripts/audit_product_media.php` — read-only video inventory
- `shopify/data/output/product_video_inventory.csv` — per-product video state
- `shopify/scripts/build_mp4_worklist.py` → `mp4_replacement_worklist.csv` — the 13 swaps
- `shopify/scripts/build_video_map.py` → `video_product_map.csv` — 17 videos + dates/durations
- `shopify/theme/product-structured-data.liquid` — the JSON-LD snippet to extend
