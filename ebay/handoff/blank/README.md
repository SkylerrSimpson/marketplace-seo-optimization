# Rule #5 blank_value handoff — how to run it

Goal: for every BLANK field in the review sheet, decide if it's blank because the
aspect **doesn't apply** to the product (→ mark `blank_value`) or just **unknown**
(→ leave blank). The judging is delegated to an external agent to save usage.

## Slices (one per agent run)
`slices/` holds the work split 150 listings per file:
- `dows_slice_00..04.jsonl` (698 listings)
- `ige_slice_00..01.jsonl` (191 listings)

## Steps
1. Give the agent **`AGENT_PROMPT.md`** + one slice file. It returns one JSON line
   per listing: `{"item_id":"..","na":[{"aspect":"..","reason":".."}]}` (only the
   aspects that DON'T apply; `na:[]` if none).
2. Save its output to `returned/<slice>.out.jsonl`.
3. Merge it:
   ```
   bash ebay/handoff/blank/verify_and_merge.sh <dows|ige> ebay/handoff/blank/returned/<slice>.out.jsonl
   ```
   This reports coverage (expected/returned/missing), appends valid lines, rebuilds
   the sheet, and re-applies rules #1–5 so the `blank_value` markers land.
4. Repeat per slice. Always check the coverage line — prior agents over-claimed and
   hallucinated ids; the merge drops unknown ids/aspects safely.

## Notes
- Resumable: `ai_check_blanks.php --tasks` regenerates only un-answered listings.
- `blank_value` is a review marker; `build_apply_set.php` drops it (never written to eBay).
- Conservative bias: if the agent is unsure an aspect applies, it leaves it OUT of
  `na` (stays blank = "unknown"). A wrong N/A is worse than an unmarked blank.
