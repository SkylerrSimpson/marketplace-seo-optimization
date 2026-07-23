# Walkthrough: one real listing through both pipelines

This traces a single real DOWS listing — item `126419572927`, "ASR Outdoor 3 in 1 Flint
Rod Striker Fire Starter Whistle" — through every stage of both pipelines, with the
actual commands and actual data at each step. If `marketplaces/ebay/README.md`'s script tables feel
abstract, this is the concrete version: what a row actually looks like before and after
each script touches it.

This listing was chosen because it's a genuinely tricky case: it's a variation listing
where children differ by **both** Color and MPN (an unusual "hidden" second variation
dimension), which exercises the vary-by guard described throughout this doc.

```
sku: SON-FLINTWHISTLE-PARENT
children: SON-FLITWHISTLE-FS385OR-VAR (Orange, MPN FSWORG-2)
          SON-FLITWHISTLE-FS386MG-VAR (Green,  MPN FSWGRN-2)
          SON-FLITWHISTLE-FS387BB-VAR (Black,  MPN FSWBLK-2)
```

---

## Pipeline 1: Item Aspects

### Step 1 — enumerate + enrich

```bash
php marketplaces/ebay/scripts/export_listings.php --account=dows     # -> listings.json
php marketplaces/ebay/scripts/enrich_listings.php --account=dows      # -> items/126419572927.json
```

`listings.json` gets the sku/variation skeleton:
```json
{
  "item_id": "126419572927",
  "sku": "SON-FLINTWHISTLE-PARENT",
  "variations": [
    {"sku": "SON-FLITWHISTLE-FS385OR-VAR", "specifics": "Color=Orange; MPN=FSWORG-2"},
    {"sku": "SON-FLITWHISTLE-FS386MG-VAR", "specifics": "Color=Green; MPN=FSWGRN-2"},
    {"sku": "SON-FLITWHISTLE-FS387BB-VAR", "specifics": "Color=Black; MPN=FSWBLK-2"}
  ]
}
```
`items/126419572927.json` gets the parent's title/category/current specifics:
```json
{
  "title": "ASR Outdoor 3 in 1 Flint Rod Striker Fire Starter Whistle Orange Green Black",
  "category_id": "166126",
  "category_path": "Sporting Goods|Camping & Hiking|Outdoor Survival Gear|Fire Starters",
  "aspects": {
    "Color": "Orange", "Brand": "ASR Outdoor", "Type": "Fire Striker",
    "Size": "4.25", "Item Height": "1.000", "Item Length": "7.000",
    "Item Weight": "0.0800", "Item Width": "3.500", "Model": "FSW-2", "..."
  }
}
```
Note the raw, unformatted numbers (`"1.000"`, `"0.0800"`) — nobody's normalized units yet.

### Step 2 — schema + gap audit

```bash
php marketplaces/ebay/scripts/fetch_category_aspects.php    # caches data/aspects/166126.json
php marketplaces/ebay/scripts/audit_listings.php --account=dows   # diffs live specifics vs. schema
```
This is where the real allowed-values list for category `166126` comes from — used later
by the write-back step to know that eBay's `Theme` aspect (a different category, but same
principle) has compound entries like `"Cartoon, TV & Movie Characters"` that must never
be comma-split.

### Step 3 — fill gaps, build the master sheet

```bash
php marketplaces/ebay/scripts/fill_aspects.php --account=dows --export=marketplaces/ebay/data/dows/input/InventoryExport_....csv
php marketplaces/ebay/scripts/ai_review.php --mode=deep --account=dows --tasks     # only if gaps remain after fill_aspects
php marketplaces/ebay/scripts/build_review_sheet.php --account=dows                # -> review_sheet.csv
```

Now every aspect for this listing is one row in `review_sheet.csv`. Four representative
rows (real, from the actual file):

| sku | aspect | source | current_value | proposed_value |
|---|---|---|---|---|
| `SON-FLINTWHISTLE-PARENT` | Brand | `child_rollup` | ASR Outdoor | ASR Outdoor |
| `SON-FLINTWHISTLE-PARENT` | Type | `current` | Fire Striker | *(blank — already correct)* |
| `SON-FLINTWHISTLE-PARENT` | Suitable For | `llm` | *(blank)* | Camping, Survival, Outdoors |
| `SON-FLITWHISTLE-FS385OR-VAR` | Color | *(variation)* | Orange | — |

That last row is the key one: its `varied_by` column says `Color` — this is a
**variation row**, not a normal aspect row. Every downstream script treats
`source=variation` rows completely differently (excluded from `ItemSpecifics`,
never unit-normalized, never touched by the review rules below).

### Step 4 — LLM judgment passes (as needed)

```bash
php marketplaces/ebay/scripts/ai_review.php --mode=current --account=dows --tasks   # audit live values
php marketplaces/ebay/scripts/ai_review.php --mode=blanks  --account=dows --tasks   # judge genuine blanks
# ... an agent (or --run) answers the task JSONL ...
php marketplaces/ebay/scripts/ai_review.php --mode=current --account=dows --merge
php marketplaces/ebay/scripts/ai_review.php --mode=blanks  --account=dows --merge
```

### Step 5 — deterministic proposing rules

```bash
php marketplaces/ebay/scripts/apply_review_rules.php --account=dows
```
> Note (2026-07): this worked example predates the Prop65 policy change — the
> aspect is no longer proposed/snapped at all, it's removed from item specifics
> entirely and moved into the description as a badge. See `docs/review-rules.md`
> §3 for current guidance; kept below as-is since it's illustrating the general
> snap-to-standard-value mechanism, not asserting current Prop65 policy.

For this listing: `California Prop 65 Warning`'s `current_value` was the OLD wording
("WARNING: This product can expose...") — rule #2 snaps `proposed_value` to the current
standard wording ("CALIFORNIA WARNING: This product can expose...").

### Step 6 — human review, merge, normalize

The reviewer works through `review_sheet.csv` (or the extracted per-round handoff CSV),
filling `approved_value`. For `Suitable For` they approved `"Backpacking, Camping, Hiking"`
(not the LLM's `"Camping, Survival, Outdoors"` proposal — a human judgment call).

```bash
php marketplaces/ebay/scripts/merge_handoff_approvals.php --account=dows --input=path/to/handoff.csv
php marketplaces/ebay/scripts/normalize_review_sheet_units.php --account=dows   # or normalize_handoff_units.php
```
`Item Weight`'s raw `"0.0800"` becomes `"0.08 lb"`, and — this is the part worth
noticing — the normalization script **appends a note to `reviewer_notes` rather than
overwriting it**, so the pre-existing child-rollup explanation survives:
```
reviewer_notes: All 4 children share '0.08'. Unit-normalized (formatting only:
                "0.08" -> "0.08 lb"; no value change).
```

### Step 7 — build the write payload

```bash
php marketplaces/ebay/scripts/build_apply_set.php --account=dows
```
`apply_set.json`'s entry for this item is the **complete, final specifics dict** — it
includes `Suitable For` with the reviewer's approved value (`"Backpacking, Camping, Hiking"`,
not the LLM's original proposal), and critically: **none of the per-child Color/MPN
rows are in this dict at all** — they were `source=variation` and got excluded, exactly
as designed.
```json
{
  "sku": "SON-FLINTWHISTLE-PARENT", "category_id": "166126",
  "specifics": {
    "Brand": "ASR Outdoor", "Type": "Fire Striker",
    "California Prop 65 Warning": "CALIFORNIA WARNING: ...",
    "Item Height": "1 in", "Item Length": "7 in", "Item Weight": "0.08 lb", "..."
  }
}
```

### Step 8 — canary write

```bash
php marketplaces/ebay/scripts/write_canary_test.php --account=dows --item=126419572927           # dry-run, prints the payload
php marketplaces/ebay/scripts/write_canary_test.php --account=dows --item=126419572927 --verify  # eBay validates, commits nothing
php marketplaces/ebay/scripts/write_canary_test.php --account=dows --item=126419572927 --live    # actually writes
```
For this exact item, the `--live` run produced `Ack: Success` — and specifically routed
`Color` + `MPN` into each child's own `VariationSpecifics` (never the shared parent
`ItemSpecifics`), which is the whole point of picking this listing as the example:
```
[SON-FLITWHISTLE-FS385OR-VAR]
    Color   Orange
    MPN     FSWORG-2
[SON-FLITWHISTLE-FS386MG-VAR]
    Color   Green
    MPN     FSWGRN-2
```

---

## Pipeline 2: Descriptions

Same listing, continuing the story — its description gets re-authored using the
aspects data Pipeline 1 just finished producing.

### Step 1 — grounding pack

```bash
php marketplaces/ebay/scripts/audit_media.php --account=dows           # -> media/126419572927.json (current HTML, images)
python3 marketplaces/ebay/scripts/extract_description_source.py         # -> desc_source_pack.jsonl
```
The pack pulls the *current* live copy as grounding material — nothing invented yet:
```json
{
  "title": "ASR Outdoor 3 in 1 Flint Rod Striker Fire Starter Whistle Orange Green Black",
  "short_description": "The design for the 3 in 1 Flint Rod Striker Fire Starter Whistle makes it easy for you to use, guiding your thumb to the perfect spot...",
  "feature_bullets": ["EMERGENCY FIRE STARTER: The design makes it easy to use guiding you to place your thumb in the perfect spot...", "..."]
}
```

### Step 2 — batch + author

```bash
python3 marketplaces/ebay/scripts/split_author_batches.py --size=135    # -> author_batches/in_NN.jsonl
# [an authoring pass against AUTHOR_PROMPT.md's task spec produces out_NN.jsonl]
python3 marketplaces/ebay/scripts/merge_authored_batch.py --account=dows author_batches/out_05.jsonl
```
The authored answer, grounded in the pack above (no invented facts, `title_issue` left
`false` since the original title is accurate):
```json
{
  "item_id": "126419572927",
  "factual": "The ASR Outdoor 3-in-1 Flint Rod Striker Fire Starter combines a fire-starting flint rod, an integrated whistle, and a key-ring, in orange. The striker design guides your thumb to the spot that creates...",
  "bullets": ["3-in-1 Tool: Fire starter, whistle, and key-ring", "Thumb-Guided Striker: Positions your grip for maximum leverage"],
  "title_issue": false, "new_title": ""
}
```

### Step 3 — render + review sheet

```bash
php marketplaces/ebay/scripts/build_description_review.php --account=dows
```
Produces `description_review.csv` (old vs. new for every field) and
`descriptions/126419572927.html` — the full rendered HTML matching
`marketplaces/ebay/tools/description-generator.html`'s template exactly.

### Step 4 — mobile-summary check

```bash
python3 marketplaces/ebay/scripts/find_mobile_desc_mismatch.py
python3 marketplaces/ebay/scripts/build_mobile_fix_review.py
```
Confirms the hidden mobile summary (the `<span property="description">` block) actually
matches the visible body — a mismatch here was a real, separately-found bug class on
other listings earlier in this project.

---

## The one rule that spans both pipelines

Every step above that touches this listing's data respects the same guard: **never
rewrite Color or MPN's value** (this item's two `varied_by` aspects), because eBay ties
sales history to the exact live variation value. That's why the walkthrough keeps
pointing it out — it's not a one-off caution, it's the load-bearing constraint that
`build_apply_set.php`, `normalize_*_units.php`, and `write_canary_test.php` are all
independently built around.
