# Sluice Boxes — Collection Page Rebuild (DRAFT for review)

**Goal:** push `/collections/sluice-boxes` from page 2 onto page 1 for the sluice cluster
(~8,000+ monthly impressions in GSC): "sluice box" (3,027, pos 12.69), "sluice box for gold"
(1,526, pos 9.3), "gold sluice box" (1,340, pos 10.9), "miners moss" (1,084, pos 19.29),
"gold sluice" (954, pos 11.52), "sluice box matting" (243, pos 17.18).

Same playbook as gold-panning-kits: intent title/meta + short above-grid intro + below-grid
Q&A buyer guide (question headings) + internal links in the theme's own link classes. No
blog/guide content. Grounded in the 8 real products in the collection.

Products verified: Miners Moss matting; Mini 12" rubber 3-riffle sluice; 50" aluminum folding
sluice; large rubber riffle matting 27x10"; 27" compact aluminum sluice (flared mouth); 34"
aluminum sluice with rubber TPR matting; multi-riffle plastic sluice; mini 12" aluminum sluice
with rubber matting.

---

## 1) SEO title + meta (test)

Current is fine but doesn't capture "gold sluice box" or "miners moss." Test:

- **Current title:** `Sluice Boxes & Sluice Box Matting | ASR Outdoor`
- **Test title:**    `Gold Sluice Boxes & Miners Moss Matting | ASR Outdoor`
- **Current meta:** `Sluice boxes and matting that capture gold when working large amounts of paydirt. A must-have addition to your ASR Outdoor gold prospecting equipment.`
- **Test meta:** `Shop aluminum, rubber and folding gold sluice boxes from 12 to 50 inches, plus Miners Moss and rubber sluice matting to capture more fine gold from your pay dirt.`

---

## 2) ABOVE-GRID intro (Shopify collection "Description" field)

~90 words for the collection banner.

```html
<p>A sluice box uses moving water to do the heavy sorting for you, trapping gold in its riffles and matting while lighter material washes away. ASR Outdoor carries gold sluice boxes in aluminum, rubber, and plastic from compact 12 inch minis up to a 50 inch folding model, plus replacement Miners Moss and rubber riffle matting. Whether you are sampling a new spot, processing buckets of pay dirt, or packing light into the backcountry, there is a sluice sized for how and where you work. The guide below explains how to choose.</p>
```

---

## 3) BELOW-GRID content block (buyer's guide, question headings, themed links)

Uses the store's `page-width` wrapper and the theme's `link underlined-link` classes so
links match the site. Add as a Custom Liquid section *after* the product grid.

```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>How to Choose a Gold Sluice Box</h2>

  <h3>New to sluicing?</h3>
  <p>Start with a mini sluice. Our 12 inch rubber and aluminum minis are light, affordable, and easy to set in a creek, so you can learn how to read water and set riffles without a big investment.</p>

  <h3>Want to process more pay dirt?</h3>
  <p>Step up to a full-size aluminum box. The 27 inch flared-mouth and 34 inch models move more material per pass and pair well with <a href="/collections/sifters-or-classifiers" class="link underlined-link">classifier screens</a> for faster cleanup.</p>

  <h3>Packing into the backcountry?</h3>
  <p>Choose the 50 inch aluminum folding sluice. It runs like a full-size box at the creek, then folds down so it carries easily on a long hike to a remote spot.</p>

  <h3>What is sluice box matting and Miners Moss?</h3>
  <p>Matting lines the bottom of the box and catches fine gold that riffles alone can miss. We stock Miners Moss and rubber riffle matting as replacements or upgrades, so you can refresh a worn box or boost the recovery of one you already own.</p>

  <h3>Aluminum, rubber, or plastic?</h3>
  <p>Aluminum boxes are the most durable and come in larger sizes for serious volume. Rubber and plastic sluices are lighter and easier on the budget, which makes them a great first box or a packable backup.</p>

  <h3>What size sluice box do I need?</h3>
  <p>A 12 inch mini is ideal for sampling and light recovery. The 27 to 34 inch boxes handle most day trips, and the 50 inch folding box is best when you want to run real volume. Many prospectors pair a sluice with a <a href="/collections/gold-pans" class="link underlined-link">gold pan</a> for finishing, or start with a complete <a href="/collections/gold-panning-kits" class="link underlined-link">gold panning kit</a> that already includes one.</p>
</div>
```

---

## 4) Placement notes (Dawn)
- **Above-grid intro** → Products → Collections → Sluice Boxes → Description (Show HTML).
- **Below-grid block** → Customize → Sluice Boxes collection → Add section → Custom Liquid →
  paste section 3, drag below the product grid.

## 5) After this: gold-pans collection (smaller pool, same playbook)
Cluster: "gold pan kit" (569, pos 11.77), "gold pan set" (120, pos 12.15), "gold pans"
(98, pos 15.08), "gold pan and classifier" (95, pos 5.6). 9 products incl. single/dual/triple
riffle pans, made-in-USA, water guide. Same rebuild when ready.
