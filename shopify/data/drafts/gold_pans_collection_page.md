# Gold Pans — Collection Page Rebuild (DRAFT for review — not deployed)

**Goal:** push `/collections/gold-pans` onto page 1 for the gold-pan cluster:
"gold pan kit" (569, pos 11.77), "gold pan classifier" (157, pos 7.2), "gold pan set"
(120, pos 12.15), "gold sifter pan" (106, pos 12.25), "gold pans" (98, pos 15.08),
"gold pan and classifier" (95, pos 5.6), "gold pan kits" (87, pos 10.77). Smaller pool than
kits/sluice, but a clean repeatable win.

Same playbook: intent title/meta + short above-grid intro + below-grid Q&A buyer guide
(question headings) + internal links in the theme's `link underlined-link` classes. No blog
content. Grounded in the 9 real products in the collection.

Products verified: Dual Riffle Gold Pans (3 colors/sizes); 4pc Dual Riffle Gold Pan Set;
14" Traditional Large Single Ridge Riffle Finishing Gold Pan with Water Guide; 10"
Traditional Single Riffle Standard Gold Pan; 11" Heavy Duty Single Riffle Gold Pan Made in
USA; 10" Triple Riffle Gold Pan; 10" Bottom Dual Riffle Gold Pan; plus a 20pc kit and a
prospecting keychain cross-listed here.

---

## 1) SEO title + meta

NOTE: verify live values before applying (the script reads current seo and is idempotent).
Likely-live values from collections_phase2.json:
- **Current title:** `Gold Pans: Single, Dual & Triple Riffle | ASR Outdoor`
- **Current meta:**  `Durable gold pans from ASR Outdoor in single, dual and triple riffle designs and multiple sizes and colors, for efficient gold separation and recovery.`

Recommended improvement (title <=70, meta <=160, ASCII):
- **Recommended title:** `Gold Pans & Pan Sets: Dual & Triple Riffle | ASR Outdoor`
  - keeps the riffle differentiation and adds "Pan Sets" for the "gold pan set" query.
- **Recommended meta:** `Shop gold pans in single, dual and triple riffle designs from 10 to 14 inches, including a heavy-duty Made-in-USA pan and a finishing pan with water guide.`

---

## 2) ABOVE-GRID intro (collection "Description" field)

~90 words for the collection banner.

```html
<p>The gold pan is the core tool of every prospector, and the right riffle design makes fine gold far easier to catch and hold. ASR Outdoor stocks single, dual, and triple riffle gold pans from 10 to 14 inches, including a heavy-duty model made in the USA and a large finishing pan with a built-in water guide. Whether you are taking your first pan to the creek, running a dual riffle for everyday recovery, or finishing concentrates down to color, there is a pan matched to the job. The guide below explains how to choose.</p>
```

---

## 3) BELOW-GRID content block (buyer's guide, question headings, themed links)

Uses the store's `page-width` wrapper and `link underlined-link` classes. Add as a Custom
Liquid section *after* the product grid.

```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>How to Choose a Gold Pan</h2>

  <h3>New to gold panning?</h3>
  <p>Start with a dual riffle pan. The riffles trap fine gold on both the coarse and fine sides, which makes the swirl far more forgiving than a smooth pan while you learn. A 10 to 14 inch pan is the right size for most beginners.</p>

  <h3>What is the difference between single, dual, and triple riffle pans?</h3>
  <p>Single riffle pans are the traditional design and great for general panning and finishing. Dual riffle pans add a second set of riffles to catch more fine gold in one pass. Triple riffle pans add even more trapping zones for aggressive recovery. More riffles generally mean better retention of fine gold.</p>

  <h3>What size gold pan should I use?</h3>
  <p>A 10 to 14 inch pan covers most prospecting. Larger pans process more material per pass, while smaller pans give you more control and are ideal for finishing your concentrates down to color.</p>

  <h3>Looking for a finishing pan?</h3>
  <p>Choose the 14 inch single ridge finishing pan with a water guide. The water guide helps you control the flow as you work down to the last of your gold without losing fine pieces.</p>

  <h3>Want a pan that lasts?</h3>
  <p>Our 11 inch heavy-duty single riffle pan is made in the USA from tough material built to take years of fieldwork.</p>

  <h3>What else do I need with a gold pan?</h3>
  <p>Pair your pan with <a href="/collections/sifters-or-classifiers" class="link underlined-link">classifier screens</a> to sort material before you pan, or step up to a <a href="/collections/sluice-boxes" class="link underlined-link">sluice box</a> to process more pay dirt. New to the hobby? A complete <a href="/collections/gold-panning-kits" class="link underlined-link">gold panning kit</a> bundles a pan with the tools to sift, recover, and store your gold.</p>
</div>
```

---

## 4) Placement notes (Dawn)
- **Above-grid intro** -> Products -> Collections -> Gold Pans -> Description (Show HTML).
- **Below-grid block** -> Customize -> Gold Pans collection -> Add section -> Custom Liquid ->
  paste section 3, drag below the product grid.

## 5) Apply title/meta (when ready, same path as gold-panning-kits)
Update this collection's `new_seo_title` / `new_seo` in collections_phase2.json, then:
`php apply_collection_metadata.php --handles=gold-pans` (dry-run) then `--apply`.
