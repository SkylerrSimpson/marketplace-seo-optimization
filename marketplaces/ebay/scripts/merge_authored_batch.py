#!/usr/bin/env python3
"""
merge_authored_batch.py — merge one returned author batch (out_NN.jsonl, one JSON
object per line: {item_id, factual, sales, bullets, mobile[, title_issue, new_title]})
into data/<acct>/output/desc_authored.jsonl.

Same load-existing -> update-by-item_id -> rewrite pattern as _pilot_author.py, just
driven from a file instead of hardcoded answers. Idempotent: re-running with the same
(or a corrected) batch file safely replaces prior entries for the same item_ids.

Usage: python3 marketplaces/ebay/scripts/merge_authored_batch.py --account=dows path/to/out_01.jsonl [path/to/out_02.jsonl ...]
"""
import json, os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)


def main():
    args = sys.argv[1:]
    acct = 'dows'
    files = []
    for a in args:
        if a.startswith('--account='):
            acct = a.split('=', 1)[1]
        else:
            files.append(a)
    if not files:
        print('usage: merge_authored_batch.py --account=dows path/to/out_01.jsonl [...]', file=sys.stderr)
        sys.exit(1)

    path = p(f'data/{acct}/output/desc_authored.jsonl')
    existing = {}
    if os.path.isfile(path):
        for line in open(path, encoding='utf-8'):
            line = line.strip()
            if not line:
                continue
            d = json.loads(line)
            existing[str(d['item_id'])] = d

    merged = 0
    for fpath in files:
        n = 0
        for line in open(fpath, encoding='utf-8'):
            line = line.strip()
            if not line:
                continue
            d = json.loads(line)
            d['item_id'] = str(d['item_id'])
            existing[d['item_id']] = d
            n += 1
        print(f'{fpath}: {n} answers merged')
        merged += n

    with open(path, 'w', encoding='utf-8') as fh:
        for d in existing.values():
            fh.write(json.dumps(d, ensure_ascii=False) + '\n')

    print(f'{acct}: {merged} answers from {len(files)} file(s) -> {path} ({len(existing)} total)')


if __name__ == '__main__':
    main()
