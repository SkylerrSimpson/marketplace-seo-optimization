# Proposing rules for the review sheet (from Ethan, 2026-06-22)

These are applied automatically by `ebay/scripts/apply_review_rules.php` (DRY-RUN;
writes only the `proposed_value` column + a `reviewer_notes` trail — never
`current_value` or `approved_value`). Run it **after** `build_review_sheet.php`
(verify_and_merge.sh already chains it last). Re-runnable / idempotent.

## 1. Always prefer an allowed value
When an aspect has an allowed list (even FREE_TEXT aspects carry eBay's
recommended values), snap the proposed value to it.
- Exact / lightly-normalised match (`12"` == `12 in`) → use the canonical option.
- Numeric value that falls **inside** a range/bucket → use that bucket.
  e.g. `Blade Size "12 in"` with `4" or Less | … | 10" or More` → **`10" or More`**.
- **Unit-aware:** a value is never snapped into a bucket of a different unit.
  e.g. `Container Size "4 fl oz"` is not snapped into `1 - 5 gal`; `Thickness "2mm"`
  is not snapped into `2 mil` (mil ≠ mm). Fractions are read correctly
  (`Item Diameter "3/16 in"` = 0.19″, not 3″). Open-ended values
  (`Age Range "3 Years & Up"`) and bare numbers against mixed-unit lists
  (`Capacity "2"` where options are `qt | L`) are flagged, not guessed.
- **Not** snapped when there's no confident match — the original value is kept and
  the row is flagged `"<value>" not in allowed list — review`. This deliberately
  avoids inventing data: a pack of `1` is **not** forced to `2`, `50 items` is not
  forced to `12`, `18 in` is not clamped to a `16 in` max. Those need your eyes.

## 2. Applicability
Decide if the attribute applies to the product; fill an appropriate value if so,
leave blank if not. This is per-product judgement — handled by the LLM fill and
your review, not auto-forced. Blank proposed values on inapplicable aspects are
intentional.

## 3. California Prop 65 Warning — blanket standard text
Default text:
> CALIFORNIA WARNING: This product can expose you to chemicals including
> bisphenol_a_(bpa), which is known to the State of California to cause cancer,
> birth defects or other reproductive harm. For more information, go to:
> www.P65Warnings.ca.gov

Exceptions (detected from the listing title):
- **Testing stones** → chemical = `silica`
- **Solder / metal** → chemical = `lead`  (detected on "solder" in the title)
- **Gear Aid** branded items → **no** Prop 65 label (proposed blank; if a value is
  currently live, the note says to DELETE it)

Applied as a *proposal* on every listing (1257 DOWS / 370 IGE). Where a listing's
live warning already equals the standard text, no change is proposed.

## 4. Country of Origin — default China
When the country is blank/unknown, propose **China**. Existing countries are never
overwritten (only the ~8–9 truly blank rows per account are filled).

> Note: rules are numbered in the reviewer notes as #1 snap, #2 Prop65, #3 Country,
> #4 Manufacturer Warranty, #5 blank_value.

## 4b. Manufacturer Warranty (rule #4)
Any **Manufacturer Warranty** aspect → propose `Limited Manufacturer Direct`.
FREE_TEXT only — the few SELECTION_ONLY warranty aspects use a fixed duration list
(`1 Year`, …) where that text isn't legal, so those are flagged for review instead.
(260 DOWS / 91 IGE set; 9 DOWS flagged.) "Seller Warranty" is left untouched.

## 5. blank_value — mark fields that DON'T apply (rule #5)
A blank field is ambiguous: blank because the aspect is irrelevant to the product
(e.g. "Cord Length" on a cordless light, "Wood Tone" on a plastic item) vs blank
because we just don't have the value (e.g. exact "Item Weight"). An LLM judges every
blank field; the **not-applicable** ones get the literal value `blank_value` (+ note
`rule #5: not applicable (<reason>)`) so the reviewer can tell them apart. Truly
"unknown" fields stay blank.

Pipeline (delegated, mirrors the current-value check):
```
ai_check_blanks.php --tasks   -> blank_check_tasks.jsonl  (one line/listing: title, category, blank aspects)
   -> external agent (ebay/handoff/blank/AGENT_PROMPT.md) returns {item_id, na:[{aspect,reason}]}
   -> ebay/handoff/blank/verify_and_merge.sh <acct> results.jsonl
        = merge + ai_check_blanks --merge -> blank_value_checks.csv
        + build_review_sheet + apply_review_rules (re-applies #1-5 cleanly)
```
To judge: DOWS 698 listings / 3,273 blank fields; IGE 191 / 808. `blank_value` is a
review marker only — `build_apply_set.php` drops it, so write-back never sends it.

---
### Run results (2026-06-22)
| | Prop65 std (silica/lead) | Gear Aid no-label | Country→China | snap exact | snap bucket | flagged for review |
|---|---|---|---|---|---|---|
| DOWS | 1205 (13/5) | 52 | 8 | 1988 | 32 | 1056 |
| IGE  | 284 (2/2)   | 86 | 9 | 656  | 6  | 437  |

Backups of the pre-rules sheets: `review_sheet.csv.prerules.bak` per account.
