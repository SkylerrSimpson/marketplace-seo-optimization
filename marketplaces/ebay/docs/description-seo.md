# eBay description SEO — audit & standardization (DRY-RUN)

Re-authors every listing's description into one company-standard HTML template. Built
on the `media/` snapshots from `audit_media.php`. Output is a review sheet for human
sign-off; write-back reuses the merge-guarded transport described in
`docs/apply-bridge.md` (description is a separate `ReviseItem`/`ReviseFixedPriceItem`
field, not an aspect).

> **This doc replaces an earlier version (last dated 2026-06-22)** that described a
> narrower plan — audit-and-flag only ~188 "weak" listings for real authoring, leave the
> rest untouched. That plan was superseded: **every** listing (1,627 total across both
> accounts) ended up going through full LLM authoring, grounded in its own real product
> data, because a shared per-listing template needs every field populated consistently
> (title, factual paragraph, sales paragraph, bullets, mobile summary) rather than a
> patchwork of touched/untouched listings.

## Pipeline (current)

```
audit_media.php --account=dows|ige
   -> media/{id}.json            current description HTML, images, price per listing

analyze_descriptions.php --account=dows|ige
   -> description_audit.csv      every listing scored on 8 SEO signals (word count,
                                  keyword coverage, bullets, schema, duplicates, etc.)
   -> desc_rewrite_tasks.jsonl   flagged-listing task file (informs, doesn't gate authoring)

extract_description_source.py
   -> desc_source_pack.jsonl     the GROUNDING pack per listing: title, price, aspects
                                  (prefers apply_set.json's merged aspects over the raw
                                  export), short_description, narrative, feature_bullets,
                                  image — the author may add wording/fix grammar but may
                                  NOT invent facts beyond what's in this pack

split_author_batches.py [--size=135]
   -> author_batches/in_NN.jsonl per-batch input, skipping already-authored listings

[authoring pass: marketplaces/ebay/scripts/AUTHOR_PROMPT.md is the task spec/contract — one JSON
 object per listing: factual, sales, bullets[], mobile, title_issue, new_title]
   -> author_batches/out_NN.jsonl

merge_authored_batch.py --account=dows|ige out_NN.jsonl [...]
   -> desc_authored.jsonl        persistent store, keyed by item_id, idempotent merge

build_description_review.php --account=dows|ige
   -> description_review.csv     21-column sheet, old vs new for every field
   -> descriptions/{itemId}.html the full proposed HTML (easy to eyeball/diff)

find_mobile_desc_mismatch.py [--threshold=95]
build_mobile_fix_review.py
   -> flags/fixes listings whose hidden mobile summary doesn't match the visible body
```

## The authoring contract (`AUTHOR_PROMPT.md`)

Every listing gets exactly six authored fields, each **grounded only in that listing's
own source pack** — the author may reorganize/fix grammar but may not invent specs,
measurements, materials, counts, or brand claims:

- **factual** — first paragraph. What the item IS: size, color, brand, material,
  contents. No hype.
- **sales** — second paragraph. Persuasive "why buy it" — must be distinct from `factual`.
- **bullets** — 3–6 Key Features as `"Label: detail"` strings.
- **mobile** — the hidden eBay-required mobile summary, ≤700 authored chars (the
  template caps the *escaped* length at eBay's real 800-char limit at render time).
- **title_issue** — boolean, **true only** when the current title is inaccurate or
  materially deficient (wrong product, missing a stated fact, contradicted claim, poor
  keyword coverage) — most titles come back `false`. Titles are not rewritten for polish.
- **new_title** — required when `title_issue: true`, ≤80 chars (eBay's hard title limit).

**Hard rule enforced throughout:** never put an MPN/UPC/EAN/GTIN/SKU/ISBN/part number in
any of `factual`/`sales`/`bullets`/`mobile`/`new_title` — those are machine codes that
belong only in the auto-rendered Product Specifications block.

## The multi-variation description gotcha (found 2026-07-06)

A listing with multiple size/length/etc. variations (`review_sheet.csv`'s `varied_by`)
shares **one description across every child sku**. Early authoring passes sometimes
stated one specific child's measurement as if it were universal fact (e.g. "This length
is 25 ft" on a cord also sold in 50/100/500/1000 ft) — misleading for buyers of the other
variants. Fixed by generalizing/ranging those specific sentences (29 listings across both
accounts needed this); watch for it in any future authoring pass on a variation listing.

## The canonical HTML template

`build_description_review.php`'s `renderFull()` must match
`marketplaces/ebay/tools/description-generator.html` (the actual in-house generator tool) structurally,
byte-for-byte:

```
<div … schema.org/Product … max-width:800px>
  <div style="display:none"><span property="description">MOBILE SUMMARY</span></div>
  <div>STORE HEADER — brand name, Our Store link, per-account</div>
  <h2>TITLE</h2>
  <p>FACTUAL paragraph</p>
  [<h3>Key Features</h3><ul>…</ul>]           (only when bullets exist)
  [<p><img …></p>]                             (only when an image URL exists)
  [<h3>Product Specifications</h3><ul>…</ul>]  (auto, from aspects; MPN/UPC pinned first)
  <p>SALES paragraph</p>
  <p><img … Prop65 badge …></p>                (2026-07: generic, every listing, alt text required)
  <p>FOOTER — &copy; year, brand name</p>
</div>
```

(Contact Us link removed 2026-07 — "Our Store" only. Prop65 badge added the
same round, see `marketplaces/ebay/docs/review-rules.md` §3.)

Two structural bugs were found and fixed comparing against the reference tool: feature
labels weren't trimmed before the `Label:` bold-split, and Product Specifications didn't
pin MPN/UPC to the top the way the manual tool does. Both are fixed in the current
`renderFull()`/`renderSpecs()`.

## Status (2026-07-06)

Both accounts fully authored and rendered: **1,257/1,257 DOWS, 370/370 IGE**. Title
rewritten (flagged + replaced) on 79 DOWS listings and 34 IGE listings — everything else
kept its original title. Verified structurally clean (0 issues) against: template
skeleton match, title ≤80 chars, mobile ≤800 escaped chars, no identifier leakage into
visible copy. `description_review.csv` (21 columns) is ready for review; copies live in
`marketplaces/ebay/data/review_bundle/eBay_{ACCT}_descriptions-standardized-v2_REVIEW.csv`.

Write-back is built (`apply_descriptions.php`, Pipeline 2 step 8 in
`marketplaces/ebay/README.md`) and has run live for both accounts —
`apply_descriptions_run.csv` shows live writes for both DOWS and IGE.
