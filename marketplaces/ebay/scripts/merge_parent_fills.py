#!/usr/bin/env python3
"""
merge_parent_fills.py — finalize the parent aspect roll-up and merge into review_sheet.

Inputs:
  data/parent_fill/parent_rollup_tasks.jsonl            all 1196 tasks
  data/parent_fill/answers/agent_judgment_answers.jsonl the agent's 246 judgment calls
Refinements applied here (per Skyler, 2026-06-23):
  - Country of Origin: canonicalize spelling/abbrev variants (CA->Canada, US/USA->United
    States, ...). If children collapse to ONE country -> fill it. Genuinely different
    countries stay BLANK (compliance).
  - Dominant categoricals (not identifier/dimension/country): if one value is >=60% of
    children, fill it (mapped to the aspect's allowed list).
  - 950 single-value tasks: fill the unanimous child value (mapped to allowed list).
Writes proposed_value + reviewer_notes + source='child_rollup' into the PARENT rows of
each account's review_sheet.csv (blank proposed_value only; never overrides). Backs up
the original and emits an audit CSV of every fill.

Usage: python3 ebay/scripts/merge_parent_fills.py [--dry-run]
"""
import csv, json, os, sys, collections, shutil, datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)
DRY = '--dry-run' in sys.argv

IDENT = {'MPN','UPC','EAN','GTIN','Model','Manufacturer Part Number'}
DIM   = {'Item Weight','Item Length','Item Width','Item Height','Size','Capacity',
         'Voltage','Blade Length'}
COUNTRY_ASPECTS = {'Country of Origin','Country/Region of Manufacture','Region of Origin','Origin'}

COUNTRY_CANON = {
    'us':'United States','u.s.':'United States','u.s.a.':'United States','usa':'United States',
    'united states':'United States','united states of america':'United States','america':'United States',
    'ca':'Canada','can':'Canada','canada':'Canada',
    'uk':'United Kingdom','u.k.':'United Kingdom','united kingdom':'United Kingdom',
    'england':'United Kingdom','great britain':'United Kingdom','gb':'United Kingdom',
    'prc':'China','cn':'China','china':'China',"people's republic of china":'China',
    'roc':'Taiwan','tw':'Taiwan','taiwan':'Taiwan',
    'in':'India','india':'India','mx':'Mexico','mexico':'Mexico',
    'de':'Germany','germany':'Germany','jp':'Japan','japan':'Japan','vn':'Vietnam','vietnam':'Vietnam',
    'pk':'Pakistan','pakistan':'Pakistan','it':'Italy','italy':'Italy','fr':'France','france':'France',
}
def canon_country(v):
    return COUNTRY_CANON.get(v.strip().lower(), v.strip())

def map_allowed(value, allowed_list, allowed_open):
    """Return a legal value for the aspect, or None if it can't be mapped to a closed list."""
    value = (value or '').strip()
    if not value: return None
    if allowed_open or not allowed_list:
        return value
    low = {a.lower(): a for a in allowed_list}
    if value.lower() in low: return low[value.lower()]
    if value.title().lower() in low: return low[value.title().lower()]
    for a in allowed_list:                      # word/substring overlap
        al = a.lower()
        if al and (al in value.lower() or value.lower() in al):
            return a
    return None

def main():
    tasks = [json.loads(l) for l in open(p('data/parent_fill/parent_rollup_tasks.jsonl'))]
    jud   = {(a['item_id'], a['aspect']): a
             for a in (json.loads(l) for l in open(p('data/parent_fill/answers/agent_judgment_answers.jsonl')))}

    # Build the final (item_id, aspect) -> {proposed, note, conf} decision for every task.
    decisions = {}
    stats = collections.Counter()
    for t in tasks:
        key = (t['item_id'], t['aspect']); asp = t['aspect']
        cv = t['child_values']; total = sum(c['count'] for c in cv)
        allowed, aopen = t['allowed_values'], t['allowed_is_open']

        # ---- country aspects: the stored allowed list is truncated (only ~27 A/B
        # countries), so don't gate on it. Canonicalize spelling/abbrev variants;
        # one country -> fill it; genuinely different countries -> blank (compliance).
        if asp in COUNTRY_ASPECTS:
            canon = {canon_country(c['value']) for c in cv if c['value'].strip()}
            if len(canon) == 1:
                only = next(iter(canon))
                raw = ", ".join(f"{c['value']}({c['count']})" for c in cv)
                note = (f"All {t['num_children']} children = {only}." if len(cv) == 1
                        else f"All children = {only} (normalized from {raw}).")
                decisions[key] = (only, note, 'high')
                stats['country_filled'] += 1
            else:
                cnts = ", ".join(f"{canon_country(c['value'])}({c['count']})" for c in cv)
                decisions[key] = ('', f"FLAG: children list conflicting countries ({cnts}). "
                                  "Left blank — set Country per variation (likely bad data on some).", 'low')
                stats['country_true_conflict'] += 1
            continue

        if len(cv) == 1:                                            # ---- unanimous single
            val = map_allowed(cv[0]['value'], allowed, aopen)
            if val:
                decisions[key] = (val, f"All {t['num_children']} children share '{cv[0]['value']}'.", 'high')
                stats['single_filled'] += 1
            else:
                decisions[key] = ('', f"Child value '{cv[0]['value']}' is not a valid {asp} "
                                  "option; left blank for reviewer.", 'low')
                stats['single_no_allowed'] += 1
            continue

        # ---- multi-value: start from the agent's judgment
        a = jud.get(key)
        proposed = (a.get('proposed_value') if a else '') or ''
        note     = (a.get('reviewer_notes') if a else '') or ''
        conf     = (a.get('confidence') if a else 'low') or 'low'

        if (not proposed and asp not in IDENT and asp not in DIM    # ---- dominant categorical
              and asp not in COUNTRY_ASPECTS and cv[0]['count']/total >= 0.6):
            val = map_allowed(cv[0]['value'], allowed, aopen)
            if val:
                minority = ", ".join(f"{c['value']}({c['count']})" for c in cv[1:])
                proposed = val
                note = f"Dominant '{cv[0]['value']}' ({cv[0]['count']}/{total}); minority: {minority}."
                conf = 'med'; stats['dominant_filled'] += 1

        if proposed:
            decisions[key] = (proposed, note, conf)
            stats['multi_filled'] += 1
        else:
            decisions[key] = ('', note or 'Mixed child values; left blank for reviewer.', conf)
            stats['multi_blank'] += 1

    # Merge into each account's review_sheet.csv (parent rows, blank proposed_value only).
    CERT = {'high': 90, 'med': 70, 'low': 50}
    audit = [['account','item_id','sku','aspect','proposed_value','confidence','reviewer_notes']]
    applied = noted = 0
    for account in ('dows', 'ige'):
        rs = p(f'data/{account}/output/review_sheet.csv')
        if not os.path.isfile(rs): continue
        rows = list(csv.DictReader(open(rs, encoding='utf-8')))
        fieldnames = rows[0].keys() if rows else []
        for r in rows:
            sku = (r.get('sku') or '').strip()
            if not sku.upper().endswith('PARENT'): continue
            if (r.get('proposed_value') or '').strip(): continue
            key = (r.get('item_id',''), r.get('aspect',''))
            if key not in decisions: continue
            val, note, conf = decisions[key]
            r['reviewer_notes'] = note                    # note written even when blank
            if val:                                       # only a fill sets value+source
                r['proposed_value'] = val
                r['source'] = 'child_rollup'
                if 'certainty' in r: r['certainty'] = str(CERT.get(conf, 60))
                applied += 1
            else:
                noted += 1
            audit.append([account, r.get('item_id',''), sku, r.get('aspect',''), val, conf, note])
        if not DRY:
            bak = rs + '.preparentfill.bak'
            if not os.path.exists(bak): shutil.copy(rs, bak)
            with open(rs, 'w', newline='', encoding='utf-8') as fh:
                w = csv.DictWriter(fh, fieldnames=list(fieldnames))
                w.writeheader(); w.writerows(rows)

    if not DRY:
        with open(p('data/parent_fill/parent_fills_applied.csv'), 'w', newline='', encoding='utf-8') as fh:
            csv.writer(fh).writerows(audit)

    print(f"{'DRY-RUN — ' if DRY else ''}parent roll-up merge")
    for k, v in sorted(stats.items()): print(f"  {k:24s} {v}")
    print(f"  -> proposed_value FILLED: {applied}")
    print(f"  -> left blank but NOTED (flags for Ethan): {noted}")
    if not DRY:
        print(f"  audit: data/parent_fill/parent_fills_applied.csv")
        print(f"  backups: data/<acct>/output/review_sheet.csv.preparentfill.bak")

if __name__ == '__main__':
    main()
