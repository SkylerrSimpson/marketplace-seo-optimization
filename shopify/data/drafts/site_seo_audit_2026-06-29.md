# ASR Outdoor — Full-Site SEO Audit (2026-06-29)

Method: live crawl of robots/sitemaps/homepage/templates + Admin API catalog sweep
(197 active products, 24 collections, content pages). Read-only.

## What's already excellent (no action)
- **Catalog: 0 gaps.** 197 products, all have meta title (none >60c) + meta description +
  product type; **all 1,893 images have alt text**; ProductGroup + aggregateRating + breadcrumb
  + VideoObject schema live.
- **Collections:** 22/24 fully optimized (title + meta + intro + below-grid guide).
- **Technical:** HTTPS, canonical tags present, robots.txt sane (facet/sort URLs disallowed),
  full sitemap set incl. new `sitemap_agentic_discovery.xml` (AI crawler discovery), Organization
  + WebSite + SearchAction (sitelinks search box) schema, logo + social sameAs present.
- Breadcrumb + 4 CTR title fixes + rock-hounding-gear redirect shipped 2026-06-29.

## HIGH priority (homepage = highest-traffic page, clear misses)
1. **Homepage `<title>` is just "ASR Outdoor" (11 chars).** Prime real estate wasted — no
   keywords. The homepage ranks for "asr outdoor" (branded) but nothing else. FIX: keyword-led
   title, e.g. `Gold Prospecting Equipment & Gold Panning Kits | ASR Outdoor`.
   WHERE: Online Store -> Preferences -> "Homepage title". (Not API-editable with current token.)
2. **Homepage H1 appears EMPTY.** The page renders an `<h1>` with no text (the hero banner has
   no heading). Every page should have one descriptive H1. FIX: give the homepage hero/first
   section a real H1 like "Gold Prospecting & Outdoor Gear" (theme customizer, banner heading).
3. **Homepage meta description is 273 chars** -> Google truncates at ~155-160. Rewrite to ~155.
   WHERE: Online Store -> Preferences -> homepage meta description.

## MEDIUM priority
4. **Content-page meta descriptions are auto-generated and too long:** FAQ 320c, Contact 320c,
   About 219c (all pull raw body text, get truncated). Hand-write ~150c metas. These pages carry
   brand/E-E-A-T weight. WHERE: each page -> Edit -> SEO section (admin, not API w/ this token).
5. **2 collections missing meta title + description:**
   - `used-gold-prospecting-equipment-and-gold-panning-gear` -> real query opportunity
     ("used gold prospecting equipment"). I CAN push via apply_collection_metadata.php.
   - `sale` -> lower value but trivial to fill.
6. **10 thin product descriptions (<200 chars)** — all minor camping/survival accessory SKUs
   (fishing fillet knife, pocket utensil, water-bottle holder, 7-in-1 multitool, glow sticks,
   mylar blanket, camping hatchet x2, sharpening stone, jumbo carabiner). Expand each to ~120+
   words for thicker, more rankable pages. descriptionHtml is API-pushable.

## LOW priority / polish
7. **Organization schema:** add `contactPoint` (customer-service phone/email) for a stronger
   brand/knowledge-panel signal; clean the empty strings in the `sameAs` array. (Theme edit.)
8. **13 variants still missing barcode/GTIN** (the known held set: 6 dupes + 7 blank).
9. **About page** is brand-first title ("ASR Outdoor: About Us") + thin (209 words) — flip to
   "About ASR Outdoor | ..." and add a few sentences of history/credibility (E-E-A-T).
10. FAQ page could host a single FAQPage JSON-LD (low value post-2023 Google restriction).

## Who actions what
- **Me (API, now):** #5 collection metas; #6 thin product descriptions (draft + push).
- **You (admin/theme):** #1/#2/#3 homepage; #4 page metas; #7/#9 schema + about copy.
- **Background:** #8 GTINs, #10 FAQ schema.
