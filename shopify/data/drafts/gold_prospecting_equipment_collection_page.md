# Gold Prospecting Equipment & Accessories — Collection Page Rebuild (DRAFT for review)

**Page:** `/collections/gold-panning-accessories` (title "Gold Prospecting Equipment", 36 products)

**Goal:** push this page onto page 1 for the broad equipment/tools/supplies head-term pool
(~4,000+ monthly impressions in GSC, mostly pos 11–24):
- "prospecting equipment" 2,014 (pos 13.81)
- "gold prospecting tools" 556 (pos 12.54) + "gold rush tools" 565 (pos 10.76)
- "gold prospecting equipment" 472 (pos 16.81)
- "gold panning equipment" 414 (pos 13.85)
- "gold mining equipment" 398 (pos 23.15)
- "gold panning supplies" 285 (pos 10.73) + "gold prospecting supplies" 184 (pos 17.15)
- "gold prospecting kit" 225 (pos 10.68) + "prospecting kit" 203 (pos 15.27)
- "prospecting gear" 155 (pos 24.46) + "gold prospecting accessories" 129 (pos 17.03)
- Bonus commercial near-win: "black sand separator" 128 (pos **4.71**) — this collection holds
  the magnetic black-sand separators, so surface them clearly.

This is the catch-all "specific tool / accessory" page, so the rebuild organizes the 36 SKUs
into shop-by-job buckets and gives the broad head terms strong topical coverage. Classifier
queries (gold classifier 544, classifier screens 407, etc.) are intentionally NOT the focus
here — those belong to /collections/sifters-or-classifiers (next collection in the plan).
We just link to it.

Same playbook: intent title/meta + short above-grid intro + below-grid Q&A buyer guide
(question headings) + internal links in the theme's `link underlined-link` classes. No blog
content. Grounded in the 36 real products.

**Product buckets (verified from the 36 SKUs):**
- Fine gold recovery: snuffer bottles (3oz/4oz), magnifying tweezers + glass vials set,
  3mL dropper pipettes, 19pc Complete Fine Gold Recovery Kit, 10pc Pocket Recovery Kit.
- Black sand separation: magnetic pick-up tool / black sand separator magnet, 5lb magnetic
  pocket separator pen.
- Digging & breaking ground: 22" and 16" 3-in-1 shovel/pick/saw, collapsible magnetic pick
  axe, 28.25" HRC steel shovel, 20oz rock pick mining hammer, 13" serrated digger trowel.
- Scoops & trowels: 12.5" riffled sand scoop, hand-scoop trowels, metal-detector sand scoops
  (8.5", 2-in-1 with probe, 14" with coin probe).
- Storage & buckets: 3.5/5 gal bucket, 10L collapsible silicone bucket, 10L bucket + stand.
- Examination: 4pc aluminum loupe set (2.5x–10x), 4x magnifying tweezers, 2" testing stone.
- Plus a 6" stackable classifier, two riffle pans, paydirt bags, and complete kits cross-listed.

---

## 1) SEO title + meta

NOTE: verify live values first (script is idempotent, reads current seo before writing).
Likely-live value from collections_phase2.json:
- **Likely-live title:** `Gold Prospecting Equipment & Accessories | ASR Outdoor`
- **Likely-live meta:**  `ASR Outdoor gold prospecting equipment and panning accessories for the extraction, recovery, cleaning, examination and storage of found gold.`

Recommended improvement (title <=70, meta <=160, ASCII):
- **Recommended title:** `Gold Prospecting Equipment & Tools | ASR Outdoor` (48 chars)
  - swaps the lower-volume "Accessories" (129 impr) for "Tools" — captures the much larger
    "gold prospecting tools" (556) + "gold rush tools" (565) pool while keeping "equipment."
- **Recommended meta:** `Gold prospecting equipment and tools from ASR Outdoor: sand scoops, snuffer bottles, black sand magnets, digging tools, recovery kits, vials and loupes.`
  - 150 chars; names the actual product categories so it matches "supplies/tools/accessories"
    searches and reads like a real shelf, not a slogan.

---

## 2) ABOVE-GRID intro (collection "Description" field)

~90 words for the collection banner.

```html
<p>Once you have a pan and a sluice, the right hand tools are what make a day on the creek productive. ASR Outdoor stocks the gold prospecting equipment that fills out a kit: sand scoops and digging tools for moving material, snuffer bottles, vials, and tweezers for collecting fine gold, magnetic separators for pulling gold from black sand, and loupes for checking your finds. Whether you are building a kit piece by piece or replacing a tool that wore out in the field, you will find the equipment matched to each step of recovery below. The guide explains what each tool does.</p>
```

---

## 3) BELOW-GRID content block (buyer's guide, question headings, themed links)

Uses the store's `page-width` wrapper and `link underlined-link` classes. Add as a Custom
Liquid section *after* the product grid.

```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>Gold Prospecting Equipment: What Each Tool Does</h2>

  <h3>What gold prospecting equipment do I actually need?</h3>
  <p>Beyond a gold pan and a sluice box, most prospectors carry a sand scoop or trowel to move material, a snuffer bottle and vials to collect and store fine gold, tweezers or a loupe to examine finds, and a digging tool to break up hard ground. If you would rather not piece it together, a complete <a href="/collections/gold-panning-kits" class="link underlined-link">gold panning kit</a> bundles the essentials in one box.</p>

  <h3>How do I collect and store the fine gold I find?</h3>
  <p>A snuffer bottle suctions up fine flakes from your pan, and glass vials keep them safe for the trip home. Our recovery kits pair a snuffer with vials, tweezers, and a dropper pipette so nothing gets lost. The 19pc Complete and 10pc Pocket recovery kits gather these finishing tools in one package.</p>

  <h3>How do I separate gold from black sand?</h3>
  <p>Black sand is heavy and tends to settle with your gold in the bottom of the pan. A magnetic separator pulls the iron-rich black sand away so your gold is left behind. Our magnetic pick-up tool and 5lb pocket separator pen both use a quick-release magnet to make cleanup fast.</p>

  <h3>What digging tools should I bring?</h3>
  <p>For loose creek gravel, a sand scoop or hand trowel moves material quickly. For packed or rocky ground, a rock pick hammer or a serrated digger breaks it up, and a folding shovel-pick-saw covers more jobs in one tool. The 22 inch and 16 inch 3-in-1 tools fold down for the pack, while the steel shovel and rock pick are built for heavier work.</p>

  <h3>Which scoop is right for me?</h3>
  <p>A riffled-edge prospector's scoop lets water and fine material drain while holding the heavies. If you also swing a detector, the metal-detecting scoops with a built-in steel probe or coin probe double as a recovery tool on the beach or in the field.</p>

  <h3>How do I examine and classify my finds?</h3>
  <p>A loupe magnifies flakes and small specimens so you can tell gold from pyrite, and a testing stone helps check a piece. To sort material by size before you pan, add <a href="/collections/sifters-or-classifiers" class="link underlined-link">classifier screens</a>. Ready to process more dirt? Step up to a <a href="/collections/sluice-boxes" class="link underlined-link">sluice box</a> or a finishing <a href="/collections/gold-pans" class="link underlined-link">gold pan</a>.</p>
</div>
```

---

## 4) Placement notes (Dawn)
- **Above-grid intro** -> Products -> Collections -> Gold Prospecting Equipment -> Description
  (Show HTML).
- **Below-grid block** -> Customize -> open the Gold Prospecting Equipment collection ->
  Add section -> Custom Liquid -> paste section 3, drag below the product grid.

## 5) Apply title/meta (when approved)
Update this collection's `new_seo_title` / `new_seo` in collections_phase2.json (handle
`gold-panning-accessories`), then:
`php apply_collection_metadata.php --handles=gold-panning-accessories` (dry-run) then `--apply`.
