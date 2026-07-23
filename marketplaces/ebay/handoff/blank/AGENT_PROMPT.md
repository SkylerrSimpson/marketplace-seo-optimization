# Task: decide which blank eBay item-specifics DON'T APPLY to the product

You are auditing eBay listings. Each input line is one product (JSON) with a list
of **blank** item-specific aspects. For each product, decide which of those blank
aspects **do not apply to that product at all** — versus aspects that *could* apply
but we simply don't know the value.

## Why
A blank field is ambiguous: it might be blank because the attribute is irrelevant
to the product (a cordless light has no "Cord Length"), or blank because it's a real
spec we just don't have (we don't know the exact "Item Weight"). We need to tell
those apart. The not-applicable ones will be marked with a sentinel `blank_value`
so the human reviewer knows they were left empty **on purpose**.

## Input (one JSON object per line)
```json
{"item_id":"124443107633","sku":"...","title":"(6 Pack) Thin Clear Flexible Plastic Kitchen Cutting Board 12 Inch x 15 Inch","category_id":"46282","category_path":"Home & Garden|Kitchen...|Cutting Boards","blanks":["Item Depth","Item Diameter","Item Weight","Making Method","Pattern","Personalization Instructions","Style","Theme","Wood Tone"]}
```

## Output (one JSON object per line — ONE per input product)
```json
{"item_id":"124443107633","na":[{"aspect":"Item Diameter","reason":"rectangular board, no diameter"},{"aspect":"Wood Tone","reason":"plastic, not wood"},{"aspect":"Theme","reason":"plain utility board"},{"aspect":"Personalization Instructions","reason":"not personalized"}]}
```
- `na` lists **only** the aspects that DON'T APPLY. Each is `{"aspect":"<exact aspect text>","reason":"<short why>"}`.
- If **none** of the blanks are not-applicable, return `{"item_id":"...","na":[]}`.
- Still output a line for **every** product.

## How to decide
Mark an aspect **N/A** (put it in `na`) only when it is **meaningless for this
product type**, e.g.:
- `Cord Length` / `Cord Type` on a **cordless** item
- `Lumens` / `Bulb Type` on something that isn't a light
- `Wood Tone` / `Wood Species` on a **plastic** or metal item
- `Blade Length` / `Blade Material` on an item with no blade
- `Shoe Size` / `Ring Size` / `Age Level` on a tool or kitchenware
- `Thread Count` on something that isn't fabric/bedding
- `Personalization Instructions` when the product isn't personalized
- `Character` / `Franchise` / `Theme` on a plain utility product with no licensing

Do **NOT** mark N/A (leave it OUT of `na`) when the aspect **could** apply but we
just don't know the value, e.g.:
- `Item Weight`, `Item Length/Width/Height`, `Item Diameter` of an item that has
  those dimensions — applicable, just unknown.
- `MPN`, `Material`, `Color`, `Brand` of an item that does have one — unknown, not N/A.

## Hard rules
1. Copy `item_id` **exactly** as given. Never invent an item_id.
2. Use **only** the aspect strings listed in that product's `blanks`. Never add an
   aspect that wasn't listed, never reword it.
3. Be **conservative**: if you're unsure whether an aspect applies, **leave it out**
   of `na` (treat as "unknown", not N/A). A wrong N/A tells the reviewer the field
   never applies, which is worse than leaving it blank.
4. Judge only from the title + category + aspect name. Don't guess specs.
5. Process **every** product line. Don't stop early. Don't claim completion you
   didn't do.

## If you automate this with a script (recommended for a full slice)
You may write a script that calls the Anthropic API to do the judging. If you do,
follow this exactly — the only deliverable is a `.jsonl` file in the output format
above, one line per input product, nothing else.

**API call shape**
- Use **this entire prompt file as the `system` prompt** (so every rule above applies).
- Put the product(s) to judge in the `user` message.
- `temperature: 0` (deterministic, consistent classification).
- Model: `claude-haiku-4-5-20251001` is fine and cheap for this classification; use
  `claude-sonnet-4-6` if you want higher fidelity. (Do not use an older model.)
- `max_tokens`: ~300 per product (enough for the `na` array).

**Batching**
- Simplest + most reliable: **one product per request** → each response is exactly
  one JSON object you append as a line. 300 small requests is cheap.
- If you batch (e.g. 10–20 products per request) to save cost, you MUST instruct the
  model (in the user message) to return **exactly one JSON object per input line, in
  the same order**, and then verify the returned count equals the sent count. If it
  doesn't match, fall back to per-product for that batch. Never accept a short batch.

**Output handling (critical)**
1. Parse each model response as JSON. If parsing fails, retry that product once; if it
   still fails, write `{"item_id":"<id>","na":[]}` for it and log the id — never drop
   the line.
2. **Echo `item_id` from the INPUT**, not from the model's output — copy it verbatim
   from the record you sent, so a model typo can't corrupt it.
3. **Drop any aspect the model returns that wasn't in that product's `blanks`** (guard
   against invented aspects) before writing the line.
4. Append each result to the output file **as you go** (so a crash is resumable);
   track processed `item_id`s and skip them on re-run.
5. When done, **assert: output line count == input line count**, and every input
   `item_id` appears exactly once. Print any missing ids. Do not report success until
   this passes.

**Write the result to** `marketplaces/ebay/handoff/blank/returned/<slice-name>.out.jsonl` and stop.
Do not call the eBay API, do not edit the review sheet, do not run the merge — the
human runs `verify_and_merge.sh`, which re-checks coverage and drops bad ids/aspects.

## Worked example
Input:
```json
{"item_id":"124544488500","title":"Gear Aid Aquaseal Wader Repair Glue 0.75 oz","category_id":"8504","category_path":"Sporting Goods|Camping & Hiking|...","blanks":["Blade Length","Item Diameter","Lumens","Material","Scent"]}
```
Output:
```json
{"item_id":"124544488500","na":[{"aspect":"Blade Length","reason":"glue, no blade"},{"aspect":"Lumens","reason":"not a light source"},{"aspect":"Scent","reason":"repair glue, scent not a feature"}]}
```
(`Item Diameter` and `Material` are left out — a tube of glue does have a size and a
material, we just don't know them: those stay blank as "unknown".)
