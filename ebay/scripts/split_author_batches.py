#!/usr/bin/env python3
"""
split_author_batches.py — split the grounding source packs into lean per-batch input
files for the description re-author agents. Skips listings that already have an
authored answer (e.g. the hand-authored pilots) so we never re-author them.

Each batch row is the minimal grounding an author needs:
  {item_id, title, short_description, narrative, feature_bullets, aspects}
(aspects pruned of Prop-65 / unit-type / identifier-only fields).

Output: data/<acct>/output/author_batches/in_<NN>.jsonl
Usage:  python3 ebay/scripts/split_author_batches.py [--size=135]
"""
import json, os, sys, glob, re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)
SIZE = int(next((a.split('=')[1] for a in sys.argv if a.startswith('--size=')), 135))

SKIP_ASPECT = {'california prop 65 warning', 'unit type', 'unit quantity', 'mpn', 'upc',
               'ean', 'gtin', 'sku', 'isbn'}

def lean_aspects(asp):
    out = {}
    for k, v in (asp or {}).items():
        if k.lower() in SKIP_ASPECT:
            continue
        v = (', '.join(v) if isinstance(v, list) else str(v)).strip()
        if v:
            out[k] = v
    return out

def main():
    grand = 0
    for acct in ('dows', 'ige'):
        done = set()
        ap = p(f'data/{acct}/output/desc_authored.jsonl')
        if os.path.isfile(ap):
            for line in open(ap):
                line = line.strip()
                if line:
                    done.add(json.loads(line)['item_id'])

        bdir = p(f'data/{acct}/output/author_batches')
        os.makedirs(bdir, exist_ok=True)
        for f in glob.glob(os.path.join(bdir, 'in_*.jsonl')):
            os.remove(f)

        rows = []
        for line in open(p(f'data/{acct}/output/desc_source_pack.jsonl')):
            d = json.loads(line)
            if d['item_id'] in done:
                continue
            rows.append({
                'item_id': d['item_id'],
                'title': d.get('title', ''),
                'short_description': d.get('short_description', ''),
                'narrative': d.get('narrative', []),
                'feature_bullets': d.get('feature_bullets', []),
                'aspects': lean_aspects(d.get('aspects', {})),
            })

        nb = 0
        for i in range(0, len(rows), SIZE):
            nb += 1
            with open(os.path.join(bdir, f'in_{nb:02d}.jsonl'), 'w', encoding='utf-8') as fh:
                for r in rows[i:i + SIZE]:
                    fh.write(json.dumps(r, ensure_ascii=False) + '\n')
        print(f'{acct}: {len(rows)} to author (skipped {len(done)} already done) -> {nb} batches in {bdir}')
        grand += len(rows)
    print(f'TOTAL to author: {grand}')

if __name__ == '__main__':
    main()
