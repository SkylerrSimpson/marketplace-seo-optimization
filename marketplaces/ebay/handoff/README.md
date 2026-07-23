# Current-value verification — handoff & merge guide

Goal: a deep LLM checks every value **already live** on eBay (the `source=current`
rows in `review_sheet.csv`) and suggests fixes where it isn't sure a value is right.
The judging is delegated to external agents, run in parallel across slices of the
catalog, to get through the whole catalog faster than one sequential pass.

## What's in this folder
- `AGENT_PROMPT.md` — paste this **whole file** to each agent as its instructions.
- `slices/dows_slice_00..08.jsonl`, `slices/ige_slice_00..02.jsonl` — the work,
  split ~150 products per slice. **Give one slice per agent** (paste/upload it, or
  paste its lines under the prompt). 9 DOWS + 3 IGE = 12 slices.
  - DOWS already had 35 products done by Claude (not in these slices). IGE is all here.

## The loop per agent
1. Give the agent `AGENT_PROMPT.md` + one slice file.
2. It returns JSONL — one line per product (`{"item_id":"..","checks":[...]}`),
   only flagged values listed, `checks:[]` when a product is clean.
3. Save its raw output to `marketplaces/ebay/handoff/returned/<slice-name>.out.jsonl`
   (make the `returned/` folder; keep one file per slice).

## Verify + merge (Claude or you, when slices come back)
Per account, concatenate the returned files into the answers log, then merge:

```bash
cd marketplace-seo-optimization
# append each agent's output to the account's answers log (KEEP a newline between files)
for f in marketplaces/ebay/handoff/returned/dows_slice_*.out.jsonl; do { cat "$f"; echo; } ; done \
  >> marketplaces/ebay/data/dows/output/current_check_answers.jsonl

php marketplaces/ebay/scripts/ai_review.php --mode=current --account=dows --merge   # writes current_value_checks.csv
php marketplaces/ebay/scripts/build_review_sheet.php --account=dows            # folds suggestions into review_sheet.csv
```
Same for `ige`. The answers log is append-only and de-duped on merge by item_id+aspect
(last line wins), so re-merging is safe.

## Trust-but-verify (prior agents over-claimed)
Before trusting a batch, check coverage — every input item_id should come back exactly once:

```bash
# count covered vs expected for a slice
comm -3 \
  <(grep -o '"item_id":"[0-9]*"' marketplaces/ebay/handoff/slices/dows_slice_00.jsonl | sort -u) \
  <(grep -o '"item_id":"[0-9]*"' marketplaces/ebay/handoff/returned/dows_slice_00.out.jsonl | sort -u)
```
Empty output = perfect coverage. Lines shown = missing (agent skipped) or hallucinated
(agent invented an id not in the slice). The merge harmlessly skips unknown item_ids,
but investigate any mismatch — it means the agent didn't process the whole slice.

## After a full account is merged
`review_sheet.csv` `source=current` rows now carry: `proposed_value` = the suggested
fix (blank when the live value is fine or flagged-without-a-fix), `certainty`, and
`reviewer_notes` = "LLM: <reason>". Reviewed-but-unflagged values show `certainty=100`.
The inventory team adjudicates in `approved_value` as usual.
