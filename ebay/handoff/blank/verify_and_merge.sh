#!/usr/bin/env bash
# Merge an agent's blank-applicability results, then re-apply the review rules so the
# 'blank_value' markers (rule #5) land in the sheet.
#
#  - keeps only item_ids that belong to <account>, dedups (last wins)
#  - reports MISSING (never returned) and unknown/hallucinated ids
#  - appends valid lines to blank_check_answers.jsonl, runs --merge, then
#    re-runs apply_review_rules.php (rules #1-5)
#
# Usage: bash ebay/handoff/blank/verify_and_merge.sh <dows|ige> /path/to/results.jsonl
set -euo pipefail
acct="${1:?usage: verify_and_merge.sh <dows|ige> <results.jsonl>}"
res="${2:?usage: verify_and_merge.sh <dows|ige> <results.jsonl>}"
root="$(cd "$(dirname "$0")/../../.." && pwd)"
tasks="$root/ebay/data/$acct/output/blank_check_tasks.jsonl"
answers="$root/ebay/data/$acct/output/blank_check_answers.jsonl"

python3 - "$tasks" "$res" "$answers" <<'PY'
import sys, re, json
tasks, res, answers = sys.argv[1:4]
acct = tasks.split('/')[-3]
def idof(line):
    m = re.search(r'"item_id":\s*"?(\d+)', line)
    return m.group(1) if m else None
exp = {idof(l) for l in open(tasks) if idof(l)}
kept, unknown = {}, 0
for l in open(res):
    l = l.strip()
    if not l or idof(l) is None:
        continue
    try: json.loads(l)
    except Exception: continue          # skip malformed JSON lines
    i = idof(l)
    if i in exp: kept[i] = l            # last line wins
    else: unknown += 1
missing = sorted(exp - set(kept))
print(f"[{acct}] expected {len(exp)} | returned {len(kept)} | "
      f"unknown(other acct/hallucinated) {unknown} | MISSING {len(missing)}")
if missing:
    print("  NOT processed (re-run before trusting):", " ".join(missing[:20]),
          "..." if len(missing) > 20 else "")
with open(answers, "a") as f:
    for l in kept.values(): f.write(l + "\n")
print(f"  appended {len(kept)} valid lines -> {answers}")
PY

php "$root/ebay/scripts/ai_review.php" --mode=blanks --account="$acct" --merge
# rebuild the sheet from scratch (clean reviewer_notes) THEN re-apply rules #1-5,
# otherwise re-running apply_review_rules over an already-tagged sheet doubles notes.
php "$root/ebay/scripts/build_review_sheet.php" --account="$acct"
php "$root/ebay/scripts/apply_review_rules.php" --account="$acct"
