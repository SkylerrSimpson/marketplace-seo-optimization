#!/usr/bin/env python3
"""
build_parent_rollup_tasks.py — assemble the parent-aspect roll-up task file for the
delegated "educated guess" agent. NO eBay writes; pure data prep.

For every PARENT listing row in the aspect review_sheet whose proposed_value is blank,
gather that aspect's value from EACH of the parent's CHILDREN (Usurper child exports,
mapped via aspect_field_map.json with fallback priority), and emit one task carrying
all the child evidence the agent needs to propose a parent value + reviewer note.

Outputs: data/parent_fill/parent_rollup_tasks.jsonl  (one task per parent-aspect)
"""
import csv, json, glob, re, sys, collections, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # ebay/
def p(*a): return os.path.join(ROOT, *a)

FIELDMAP = json.load(open(p('data/aspect_field_map.json')))['usurper']
def norm(s): return re.sub(r'\s+', ' ', (s or '').strip().lower())

def cols_for(aspect):
    v = FIELDMAP.get(norm(aspect))
    if v is None: return []
    return v if isinstance(v, list) else [v]

def child_exports(account):
    """All child export CSVs for an account (DOWS subdir; IGE re-exports in root)."""
    if account == 'dows':
        return glob.glob(p('data/parent_fill/exports/DOWS/*.csv'))
    return glob.glob(p('data/parent_fill/exports/IGE/*.csv')) + \
           glob.glob(p('data/parent_fill/exports/*.csv'))   # IGE landed in root

def merge_children(account):
    """parent.sku -> list of child dicts (col->value), deduped by child sku, cols unioned."""
    by_sku = {}
    for f in child_exports(account):
        try: rd = list(csv.DictReader(open(f, encoding='utf-8')))
        except Exception: continue
        if not rd or 'parent.sku' not in rd[0]: continue
        for r in rd:
            s = (r.get('sku') or '').strip()
            if not s or s.upper().endswith('PARENT'): continue  # children only
            row = by_sku.setdefault(s, {})
            for k, val in r.items():
                val = (val or '').strip()
                if val and not row.get(k):  # first non-empty wins across passes
                    row[k] = val
            row['parent.sku'] = (r.get('parent.sku') or '').strip()
    out = collections.defaultdict(list)
    for s, row in by_sku.items():
        par = row.get('parent.sku', '')
        if par: out[par].append(row)
    return out

def child_value(child, aspect):
    for c in cols_for(aspect):
        v = (child.get(c) or '').strip()
        if v: return v
    return ''

def main():
    tasks = []
    for account in ('dows', 'ige'):
        children = merge_children(account)
        rs = p(f'data/{account}/output/review_sheet.csv')
        if not os.path.isfile(rs):
            print(f'  (no review_sheet for {account})'); continue
        for r in csv.DictReader(open(rs, encoding='utf-8')):
            sku = (r.get('sku') or '').strip()
            if not sku.upper().endswith('PARENT'): continue
            if (r.get('proposed_value') or '').strip(): continue   # already filled -> keep
            kids = children.get(sku, [])
            if not kids: continue
            aspect = r.get('aspect') or ''
            if not cols_for(aspect): continue   # aspect not mapped to any Usurper column
            vals = [child_value(k, aspect) for k in kids]
            vals = [v for v in vals if v]
            if not vals: continue                # no child evidence for this aspect
            counts = collections.Counter(vals)
            allowed = (r.get('allowed_values') or '').strip()
            allowed_list = [a.strip() for a in allowed.split('|') if a.strip()] if allowed else []
            # Only SELECTION_ONLY aspects are a CLOSED list; FREE_TEXT (and blank mode)
            # accept any value — the allowed_values are merely suggestions there.
            mode = (r.get('mode') or '').strip().upper()
            enumerated = mode == 'SELECTION_ONLY' and 0 < len(allowed_list) <= 400
            tasks.append({
                'account': account,
                'item_id': r.get('item_id', ''),
                'sku': sku,
                'name': r.get('name') or r.get('title') or '',
                'aspect': aspect,
                'cardinality': r.get('cardinality', ''),
                'mode': r.get('mode', ''),
                'current_value': (r.get('current_value') or '').strip(),
                'child_values': [{'value': v, 'count': c} for v, c in counts.most_common()],
                'num_children': len(kids),
                'allowed_values': allowed_list if enumerated else [],
                'allowed_is_open': not enumerated,
            })
    out = p('data/parent_fill/parent_rollup_tasks.jsonl')
    with open(out, 'w', encoding='utf-8') as fh:
        for t in tasks:
            fh.write(json.dumps(t, ensure_ascii=False) + '\n')

    # summary
    by_acct = collections.Counter(t['account'] for t in tasks)
    multi = sum(1 for t in tasks if len(t['child_values']) > 1)
    single = len(tasks) - multi
    parents = len({(t['account'], t['sku']) for t in tasks})
    print(f'wrote {out}')
    print(f'tasks={len(tasks)}  (dows={by_acct["dows"]} ige={by_acct["ige"]})')
    print(f'distinct parents with fillable aspects={parents}')
    print(f'single-value (deterministic)={single}  multi-value (needs judgment)={multi}')

if __name__ == '__main__':
    main()
