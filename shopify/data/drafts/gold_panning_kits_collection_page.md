# Gold Panning Kits — Collection Page Rebuild (DRAFT v2 for review)

**Goal:** push `/collections/gold-panning-kits` from position ~9–12 onto page 1 for the
"best gold panning kit [for beginners / for starting out / with sluice box]" cluster
(~30,000 monthly impressions in GSC, ~0% CTR because we sit on page 2).

**Why this page, not a blog post:** this exact URL already ranks pos 9–12 for these
queries. We are nudging an already-relevant page to match buyer-guide intent, not building
authority from scratch. Fastest available win.

**v2 changes:** removed all em-dashes and stacked hyphens; rewrote in plain professional
prose; removed the Paydirt section (those kits are a separate collection) and replaced it
with one internal link; beginner copy now reflects only confirmed kit contents.

Accuracy: verified against all 26 products in the collection. "Dual riffle" spelled the way
the catalog spells it (no hyphen). Sluice-box claim backed by 8+ kits in the collection.

---

## 1) SEO title + meta (CTR + relevance)

LIVE values (verified from page source 2026-06-24 — NOT the stale phase-2 values):
- **Live title:** `Gold Panning Kits for Beginners & Pros | ASR Outdoor`
- **Live meta:**  `Gold panning kits for beginners and experienced prospectors - complete sets with gold pans, classifiers and sluice boxes to start finding gold.`

Recommended improvement (keep under ~60 char title / ~155 char meta):
- **Recommended title (beginner-led):** `Best Gold Panning Kits for Beginners & Pros | ASR Outdoor`
  - mirrors the dominant "best gold panning kit for beginners" cluster (~13k impr).
- **Alternative title (sluice-led):** `Gold Panning Kits with Sluice Box & Pans | ASR Outdoor`
  - targets the single biggest query "best gold panning kit with sluice box" (9,612 impr).
- **Recommended meta:** `Complete gold panning kits with dual riffle pans, classifiers, snuffer bottle and sluice box options. From beginner sets to pro kits to start finding gold.`

---

## 2) ABOVE-GRID intro (Shopify collection "Description" field)

Short on purpose so it does not push products down. ~90 words. This is what Dawn shows in
the collection banner.

```html
<p>A gold panning kit puts the tools for finding and keeping gold in one box, so you do not have to buy each piece on its own. ASR Outdoor kits combine classifier screens and dual riffle gold pans with the finishing tools that recover fine gold, including a snuffer bottle, glass vials, and tweezers. Beginners can start with a simple set, river prospectors can choose a kit with a sluice box, and serious diggers can move up to a bucket kit for larger volumes.</p>
```

---

## 3) BELOW-GRID content block (buyer's guide + FAQ)

This is the part that matches "best…for beginners" intent. Uses the store's existing
`page-width` wrapper and a question-heading Q&A flow (question headings can win People Also
Ask / featured snippets). In Dawn, add it as a **Custom Liquid** section *after* the product
grid so products stay at the top (placement notes at the bottom).

```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>How to Choose a Gold Panning Kit</h2>

  <h3>New to gold panning?</h3>
  <p>Start with a beginner kit. Our 5pc, 7pc, and 10pc starter sets include a gold pan, snuffer bottle, glass vials, and magnifying tweezers, which covers the basics without overspending while you learn.</p>

  <h3>Want to recover more gold?</h3>
  <p>Move up to a kit with a sluice box. The 24pc Deluxe pairs the pans and classifier screens with a 50 inch aluminum folding sluice box and a 30L backpack, so you can run more material in less time. Lighter kits with a mini sluice are also available if you want to pack small.</p>

  <h3>Heading into the backcountry?</h3>
  <p>A backpack kit keeps everything portable. The 14pc to 24pc packs add a 30L rucksack or drawstring bag and a collapsible bucket, so the whole setup goes in and out with you.</p>

  <h3>What comes in a gold panning kit?</h3>
  <p>Most kits combine gold pans, classifier screens, a snuffer bottle, glass vials, and tweezers. The larger complete and ultimate kits also add a rock pick hammer and a 5 gallon bucket for classifying.</p>

  <h3>What size gold pan is best for beginners?</h3>
  <p>A pan between 10 and 14 inches works well for most first timers. It holds enough material to be productive while staying easy to control.</p>

  <h3>What is the best gold panning kit for beginners?</h3>
  <p>A simple starter kit with a gold pan, classifier, snuffer bottle, and vials gives a first timer everything they need to get going. A kit with a sluice box is a natural next step as your skills grow.</p>
</div>
```

Optional internal link to add in the copy (helps the topic cluster):
`Want to practice at home? Our <a href="/collections/paydirt-kits">paydirt kits</a> include
material with real, recoverable gold.`

---

## 4) FAQ schema (JSON-LD) — optional

Honest expectation: Google restricted FAQ rich results to government and health sites in
2023, so an ecommerce store will most likely NOT get the expandable snippet. Include this
only as low-cost insurance; the visible FAQ above does the real work. Text must match the
visible FAQ. Skip this entirely if you would rather keep it simple.

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {"@type":"Question","name":"What comes in a gold panning kit?","acceptedAnswer":{"@type":"Answer","text":"Most kits include a gold pan and classifier screens, plus finishing tools such as a snuffer bottle, glass vials, and tweezers. Larger ASR Outdoor kits also add a mini or folding sluice box and a carry backpack."}},
    {"@type":"Question","name":"What is the best gold panning kit for beginners?","acceptedAnswer":{"@type":"Answer","text":"A complete beginner kit with dual riffle pans and stackable classifier screens. It gives you everything needed to pan, sift, collect, and store gold, and the riffle pans make the technique easier to learn."}},
    {"@type":"Question","name":"What size gold pan is best for a beginner?","acceptedAnswer":{"@type":"Answer","text":"A pan between 10 and 14 inches works well for most beginners. It holds enough material to be productive while staying easy to control."}},
    {"@type":"Question","name":"Do I need a sluice box, or is a panning kit enough?","acceptedAnswer":{"@type":"Answer","text":"A pan and classifier kit is enough to get started. Add a sluice box when you want to process more gravel in less time. Several ASR Outdoor kits include a folding sluice if you decide to expand later."}},
    {"@type":"Question","name":"Is gold panning legal?","acceptedAnswer":{"@type":"Answer","text":"Recreational gold panning is legal on most public waterways, but rules vary by state and by land type. Check local regulations before you go. Washington, for example, permits recreational panning on many rivers under state guidelines."}}
  ]
}
</script>
```

---

## 5) Placement notes (Dawn theme)

- **Above-grid intro** → Shopify admin → Products → Collections → Gold Panning Kits →
  **Description** box (paste section 2 HTML in "Show HTML" mode). Make sure the collection
  template banner has "Show collection description" enabled.
- **Below-grid guide + FAQ** → Online Store → Themes → Customize → open the **Gold Panning
  Kits collection** → Add section → **Custom Liquid** → paste section 3 (and section 4 if
  you choose). Drag it below the product-grid section so products stay above the fold.
- Internal links: the paydirt link is in the copy. Consider also linking "classifier
  screens" and "sluice box" to their collections to strengthen the cluster.

## 6) CTR quick wins (separate from this page, high ROI)
- `black sand separation` — pos **1.3**, 1,097 impr, **0% CTR** (~270 clicks/mo lost). The
  ranking page's title/meta almost certainly does not say "black sand separation." Find the
  URL in GSC and rewrite its title/meta to lead with that phrase.
- `gold panning kit with sluice box` (pos 5.34) and `best gold panning kit with sluice box`
  (pos 9.02) — make sure the 24pc Deluxe product's SEO title leads with "Gold Panning Kit
  with Sluice Box."
