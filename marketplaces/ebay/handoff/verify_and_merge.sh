#!/usr/bin/env bash
# Verify an agent's audit_results.jsonl against the task set, then merge it.
# - keeps only item_ids that belong to <account> (drops other-account/hallucinated)
# - dedups (last line wins), reports which products are MISSING (never returned)
# - appends valid lines to the answers log, then rebuilds review_sheet.csv
#
# Usage: bash ebay/handoff/verify_and_merge.sh <dows|ige> /path/to/audit_results.jsonl
set -euo pipefail
acct="${1:?usage: verify_and_merge.sh <dows|ige> <results.jsonl>}"
res="${2:?usage: verify_and_merge.sh <dows|ige> <results.jsonl>}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
tasks="$root/ebay/data/$acct/output/current_check_tasks.jsonl"
answers="$root/ebay/data/$acct/output/current_check_answers.jsonl"

python3 - "$tasks" "$res" "$answers" <<'PY'
import sys, re
tasks, res, answers = sys.argv[1:4]
def idof(line):
    m = re.search(r'"item_id":\s*"?(\d+)', line)
    return m.group(1) if m else None
exp = {idof(l) for l in open(tasks) if idof(l)}
kept, unknown = {}, 0
for l in open(res):
    l = l.strip()
    if not l or idof(l) is None:
        continue
    i = idof(l)
    if i in exp:
        kept[i] = l          # last line wins
    else:
        unknown += 1
missing = sorted(exp - set(kept))
print(f"[{tasks.split('/')[-3]}] expected {len(exp)} | returned {len(kept)} | "
      f"unknown(other acct/hallucinated) {unknown} | MISSING {len(missing)}")
if missing:
    print("  NOT processed (re-run these before trusting):",
          " ".join(missing[:20]), "..." if len(missing) > 20 else "")
with open(answers, "a") as f:
    for l in kept.values():
        f.write(l + "\n")
print(f"  appended {len(kept)} valid lines -> {answers}")
PY

php "$root/ebay/scripts/ai_review.php" --mode=current --account="$acct" --merge
php "$root/ebay/scripts/build_review_sheet.php" --account="$acct"
# Ethan's proposing rules (allowed-value snap, Prop65 blanket, Country default).
# MUST run last — build_review_sheet regenerates proposed_value from scratch.
php "$root/ebay/scripts/apply_review_rules.php" --account="$acct"
