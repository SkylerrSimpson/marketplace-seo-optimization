# ASR Outdoor — Deep SEO Audit #2 (2026-06-29)

Second pass, focused on dimensions the first audit didn't cover: uniqueness, canonicals,
orphans, performance, heading structure, blog-level SEO. Read-only.

## Confirmed live (today's changes propagated)
- Homepage title: "ASR Outdoor: Gold Prospecting, Rock Hounding & Outdoor Gear" -- LIVE
- Homepage meta: new 145c version -- LIVE
- About title: "About ASR Outdoor | Gold Prospecting & Outdoor Gear" -- LIVE

## Clean / no action (good results)
- **0 duplicate SEO titles, 0 duplicate meta descriptions** across 197 products (all unique).
- **Canonicals correct**: /collections/x/products/y canonicalizes to /products/y (no dup-URL bloat).
- **Heading structure good**: product pages have exactly 1 H1 (the product title).
- **Blog SEO solid**: sample article (rock-hounding-101) has a proper title, 137c meta, multiple
  H1/heading structure, and valid Article/BlogPosting schema.
- **Images**: HTML references .jpg/.png but Shopify's CDN auto-serves WebP via content negotiation,
  so image format is NOT a real issue (no action).

## FINDING 1 (actionable) — 9 ORPHAN PRODUCTS (in no collection but frontpage)
These have no category path, so they get weak internal linking and are hard for shoppers + Google
to discover. Suggested homes:
- asr-outdoor-30l-heavy-stitch-gold-panning-backpack...   -> gold-panning-accessories
- orange-heavy-duty-abs-plastic-tent-stakes...            -> camping-gear
- serrated-digger-beach-combing-metal-detector...         -> metal-detecting-equipment
- 20pc-beach-combing-metal-detector-gold-panning-kit      -> metal-detecting-equipment
- paracord-bundle-100-feet-x-15-inch-7-strand             -> survival-gear (or paralace)
- solid-titanium-ice-pick-9-25                            -> survival-gear
- grooved-angle-sharpening-stone-for-fish-hooks-blades    -> survival-gear / outdoor-knives
- 400lb-neodymium-magnet-fishing-kit-with-paracord        -> no clean home (magnet fishing) *
- nylon-fishing-vest-size-large                           -> no clean home (no Fishing collection) *
(* 2 items have no matching category -- either leave, or consider a Fishing/Treasure collection.)
FIX MECHANISM TBD: depends whether the base collections are smart (need matching tag/type) or
manual (add directly). Determine via collection ruleSet, then tag/add.

## FINDING 2 (worth a look) — homepage/script weight (Core Web Vitals)
- Homepage HTML ~699 KB with ~95 <script> tags; product page ~424 KB with ~78 scripts.
- The high script count points to third-party APP bloat, which hurts Core Web Vitals (LCP/INP)
  -- a real ranking + UX factor. Lazy-loading IS in use (102 lazy imgs on home), which is good.
- ACTION: run PageSpeed Insights / Lighthouse on the homepage + a product page for real CWV
  numbers (can't measure true CWV from a server-side fetch). Then audit installed apps and remove
  any unused ones -- the cheapest CWV win.

## Net
Site is technically very clean (uniqueness, canonicals, headings, schema, blog all good). Only two
real items: (1) fix the 9 orphans, (2) check Core Web Vitals + prune unused apps.
