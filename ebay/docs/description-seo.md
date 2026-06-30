# eBay description SEO — audit & rewrite (DRY-RUN)

Task #5 of the media work: improve the SEO of listing descriptions. Built on the
`media/` snapshots from `audit_media.php`. **No eBay writes** — output is a review
sheet for Ethan, gated downstream on prod write creds + Scott sign-off (same as
the aspect pipeline).

## Key finding
The descriptions are mostly healthy, not broken. Avg SEO score 92/100 (DOWS),
89/100 (IGE); median 400/240 words; 100% median title-keyword coverage; nearly all
already have bullets + schema + intro keywords; almost no duplicate content. So
this is **not** a 1,600-listing rewrite — only ~188 listings genuinely need work.

## Pipeline
```
analyze_descriptions.php  --account=dows|ige
   -> description_audit.csv      every listing scored on 8 SEO signals + issues + treatment
   -> desc_rewrite_tasks.jsonl   only the flagged listings (title, aspects, current copy, issues)

[author copy: {item_id, treatment, headline, intro, bullets[]}]  -> desc_rewrite_answers.jsonl

build_description_review.php --account=dows|ige
   -> description_review.csv     current vs proposed HTML, score_before, issues, + approved/notes cols
   -> descriptions/{itemId}.html the full proposed HTML (easy to eyeball/diff)
```

## Scoring signals (`analyze_descriptions.php`)
word_count, title-keyword coverage, key-aspect coverage, bullets, heading, schema
markup, keyword-in-intro, duplicate-body detection → 0–100 score + issues[].

## Every listing is standardized to ONE template
`build_description_review.php` re-renders **every** listing (all 1,627), not just
the 188, through one canonical template so the description HTML style is identical
across the whole catalog:

```
<div … schema.org/Product>
  <h2> keyword headline / title
  <p property="description"> intro paragraph
  [<h3>Key Features</h3><ul>…</ul>]      (only when real feature bullets exist)
  <h3>Product Specifications</h3><ul>…</ul>   (auto, from the listing's aspects)
  <meta property="description" …>
```
This removes the inconsistent per-listing chrome (hidden `<!-- MOBILE DESCRIPTION -->`
blocks, inline `<style>`, store nav/“About Us/Contact Us”, MSRP lines).

Copy sourcing per listing (the `change_type` column tells Ethan which):
- **Full rewrite / Full rebuild** (the weak/near-empty 83) — authored headline,
  intro and selling bullets.
- **Copy improved + standardized** (the 105 that were OK) — authored keyword lead +
  the listing's own clean `short_description`, plus real feature bullets extracted
  from the old HTML.
- **Reformatted to standard style (copy kept)** (the ~1,439 already-valid) — copy
  unchanged: intro is the listing's clean `short_description`, just re-rendered in
  the standard template.

The specs list is generated FROM each listing's aspects (Prop 65 legal text and
internal-only fields like Unit Type/Quantity skipped). Feature bullets are pulled
from existing `<li>`s with store-nav/policy boilerplate filtered out; no features
are invented, so listings whose originals had none simply omit the Key Features
section (intro prose still carries them).

## Results (2026-06-22)
| | flagged total | copy_rewrite | restructure | tier2_tweak |
|---|---|---|---|---|
| DOWS | 130 | 39 | 5 | 86 |
| IGE  | 58  | 34 | 5 | 19 |

After authoring + render, **188/188** proposed descriptions have a keyword in the
intro, bullet structure, schema markup, and an aspect/spec section. Duplicate-body
pairs were given distinct copy. The 1,544 already-strong descriptions were left
untouched on purpose.

## Status / gating
DRY-RUN. `description_review.csv` is ready for Ethan to approve (fill `approved`
y/n + `reviewer_notes`). Write-back of approved descriptions is gated on prod write
creds/scope + Scott sign-off, and would reuse the merge-guarded transport from
`docs/apply-bridge.md` (description is a separate ReviseItem field, not an aspect).
