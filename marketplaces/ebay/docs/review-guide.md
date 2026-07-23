# eBay Item-Specifics — Reviewer Guide

We auto-filled eBay item specifics (aspects) for DOWS + IGE from Usurper data, rules,
and AI. **None of this is live on eBay yet** — it's all proposed, pending your review.
Your job: confirm/correct the values currently live on eBay AND fill the gaps you can.

## 0. `review_sheet.csv` — the master sheet (every listing, every aspect)
One row per (listing × aspect), covering **every** eBay listing — both the values
**already live on eBay** and the **gaps** we propose to fill. Key columns:

- **`sku`** — always filled. For a variation child, this is the child's SKU.
- **`varied_by`** — filled **only on variation rows**: the aspect that child varies on
  (Color, Size, Style…). A child that varies on two aspects gets one row per aspect.
- **`current_value`** — what is **live on eBay right now** (blank if this is a gap).
  Check these for correctness; fix in `approved_value` if wrong.
- **`proposed_value`** — our fill for a gap (blank if the aspect is already live).
- **`source`** — `current` (live on eBay) · `variation` (a child's varied value) ·
  `usurper`/`rule`/`default` (trusted fill) · `llm` (AI guess) · `none` (blank gap).
- **`approved_value` / `reviewer_notes`** — yours to fill.
- **`mode`/`allowed_values`** — `SELECTION_ONLY` must match a value in `allowed_values`.

The two queues below are filtered slices of this sheet for focused passes.

You'll also work from two files per account (in `marketplaces/ebay/data/{dows,ige}/output/`):

## 1. `hand_fill_queue.csv` — every blank gap, rated by effort
Holds **all** the still-blank aspects, with a **`fillability`** column so you choose
how far to go. Sorted easy-first, then grouped by `category_id` (similar products together).

- **`fillability = easy`** — readable straight off the product photo/title (Color, Material,
  Theme, Pattern, Style, Shape, Character…). Do these first; biggest bang for the buck.
- **`fillability = medium`** — needs a little research (Product Line, Series, Vehicle Make…).
  Optional; go as deep as you have time for.
- **`fillability = hard`** — supplier-only / spec / collectible (MPN, dimensions, warranty,
  autograph…). Usually **leave blank** unless you have the supplier data.

How to fill:
- Put your value in the **`approved_value`** column.
- If `mode = SELECTION_ONLY`, your value **must** be one of the options in `allowed_values`
  (eBay rejects anything else). `FREE_TEXT` = anything, ≤65 chars.
- Use `name` / `title` / `sku` to identify the product.
- If it genuinely doesn't apply (e.g. "Engine Type" on a t-shirt), leave it blank.

## 2. `llm_spotcheck_queue.csv` — check the AI's guesses
Every value the AI proposed, **sorted lowest-certainty first**.

- **`certainty` < 70** → review these first; the AI wasn't confident.
- **`certainty` ≥ 80** → usually safe; skim and rubber-stamp.
- If the AI value is right: leave `approved_value` blank (we'll use the proposed value).
- If it's wrong: put the correct value in **`approved_value`** (or `DELETE` to drop it).
- Notes/uncertainty → **`reviewer_notes`**.

## Rules of thumb
- A **wrong** value is worse than a blank — when unsure, leave blank or note it.
- Required aspects are already filled (eBay enforces them); everything here is the
  optional/recommended set that improves search ranking.
- Don't touch the other columns — just `approved_value` and `reviewer_notes`.

## What happens next
Once you've filled `approved_value`s, we run `build_apply_set.php`: your
`approved_value` always wins; where you left it blank we use the proposed value
(deterministic always, AI only above the certainty threshold). Then it's a guarded,
dry-run-first write-back to eBay — pending production write access + sign-off.
