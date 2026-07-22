# Task: Verify eBay item-specific values (give this whole file to the agent)

You are auditing the item-specific values that are **currently live** on a set of
eBay listings. For each product you get its title, category, and the aspect values
that are live right now. Your job: decide whether each live value is **correct** for
that product. Where you are **not 100% sure a value is right**, propose a corrected
value and a certainty score. Use **only** the information in each line (title,
category path, the values themselves) + general world knowledge. Do **not** invent
specs you can't see.

## Input

You'll be given a `.jsonl` file (one JSON object per line). Each line is one product:

```json
{"item_id":"126487251794","sku":"KOL-...","name":"Girls Gold Star Bundle with Barbie Dangle Earrings","title":"Girls Gold Star Bundle with Barbie Dangle Earrings Dress Up","category_id":"19172","category_path":"Toys & Hobbies|...|Dress-Up, Costumes","current":[{"aspect":"Brand","value":"Mattel","mode":"FREE_TEXT","allowed":null},{"aspect":"Year","value":"NEW","mode":"FREE_TEXT","allowed":null}, ...]}
```

- `current[]` = the live values to check. `mode` is `FREE_TEXT` (any text) or
  `SELECTION_ONLY` (value MUST be one of `allowed`). `allowed` is the list of valid
  options, or `null` when free-text or when the list was too long to include.

## Output — what you return

Return **one JSON line per input line, in the same order**. Each line:

```json
{"item_id":"<COPIED EXACTLY FROM INPUT>","checks":[ ...only the FLAGGED values... ]}
```

- List **only** the aspects you are flagging as wrong/suspect. Each flagged aspect:
  ```json
  {"aspect":"<exact aspect name>","ok":false,"value":"<your suggested fix or \"\">","certainty":NN,"reason":"<short why>"}
  ```
- If **nothing** on a product is wrong, still output the line with empty checks:
  `{"item_id":"126487251794","checks":[]}`
- `certainty` = 0–100, how sure you are of your suggestion / that the live value is
  wrong. Be honest: 90+ only when it's clearly wrong and the fix is obvious; 30–60
  for "looks off but unsure"; low when you can't propose a real fix.
- `value` = your corrected value. Leave it `""` when the live value is junk but you
  have no good replacement (e.g. a bad `Year`) — the flag + reason still helps.
- If the aspect is `SELECTION_ONLY`, your suggested `value` **must** be copied
  exactly from that aspect's `allowed` list. Keep any value **≤ 65 characters**.

## What to flag (be a careful auditor, not a rewriter)

Flag a value when it is wrong, mismatched, junk, or a placeholder. Common cases:

1. **Junk `Year` / `Year Manufactured` = "NEW"** (or other non-year text) → flag,
   `value":""`, certainty ~20, reason "‘NEW’ is not a valid year".
2. **Placeholder `Character` / `Character Family` / `Brand` = "Officially Licensed"**
   → flag, `value":""`, certainty ~30, reason "not a real <aspect>".
3. **Wrong franchise / character** — e.g. `Character` lists "Spider-Man" on a DC
   **Superman** product → suggest the correct one, certainty 90+.
4. **Mismatched `Type`** — e.g. a fishing fillet knife tagged `Type=Hunting`
   (→ "Fishing"); a tool pouch tagged `Type=Tool Belt` (→ "Tool Pouch").
5. **Miscategorized product** — values that make no sense for the item (e.g. a fabric
   care kit carrying `Insulation Type=Down`, `Shell Material=Down`). Flag each,
   low certainty, reason notes the mismatch.
6. **Truncated / marketing junk** in `Features` (ends mid-sentence, or is ad copy
   like "HIGH QUALITY CONSTRUCTION – Our knives are made of") → suggest a trimmed
   value or `""`.
7. **SELECTION_ONLY value not in `allowed`** → suggest the closest allowed option.

## What to LEAVE ALONE (mark ok by omitting from checks)

- Codes you can't verify: `MPN`, `UPC`, `Model`, `EAN`, SKU-like strings — leave ok.
- Dimensions / weights (`Item Width`, `Thickness`, etc.) — leave ok unless absurd.
- A plausible value, even if you might phrase it differently — leave ok. Only flag
  when you genuinely think it's wrong. **A wrong suggestion is worse than leaving a
  fine value alone.**

## CRITICAL rules (these were violated before — follow exactly)

- **Copy `item_id` character-for-character** from the input. NEVER invent, guess, or
  reformat an item_id. NEVER output an item_id that wasn't in your input.
- **Process EVERY input line. Output EXACTLY one line per input line, same order.**
  Do not stop early. Do not claim "done" without having output every line.
- Output **only** the JSONL lines — no commentary, no markdown fences, no summary.
- One value per aspect (do not return comma-lists for a single-value aspect).

## Tiny worked example

Input:
```json
{"item_id":"127239775819","title":"DC Comics Superman 2pc Cinch Bag","category_id":"260988","category_path":"Clothing...|Kids|Backpacks & Bags","current":[{"aspect":"Character","value":"Spider-Man, Officially Licensed, Superman","mode":"FREE_TEXT","allowed":null},{"aspect":"Brand","value":"DC Comics","mode":"FREE_TEXT","allowed":null},{"aspect":"Year","value":"NEW","mode":"FREE_TEXT","allowed":null}]}
```
Output:
```json
{"item_id":"127239775819","checks":[{"aspect":"Character","ok":false,"value":"Superman","certainty":95,"reason":"Spider-Man is Marvel; this is a DC Superman product"},{"aspect":"Year","ok":false,"value":"","certainty":20,"reason":"'NEW' is not a valid year"}]}
```
(`Brand=DC Comics` was correct, so it's omitted.)
