#!/usr/bin/env python3
"""
find_mobile_desc_mismatch.py — flag CURRENT eBay descriptions where the hidden mobile
description (schema.org <span property="description"> in the display:none block) does
NOT match the visible description by words. Read-only; reads the media/ snapshots.

Metric: CONTAINMENT — of the unique words in the mobile description, what % also appear
in the visible description. The mobile block is a SHORT summary of a longer body, so a
faithful mobile scores ~100%; a mobile blurb with different/stale wording scores low.
(A symmetric overlap would falsely flag every description whose body is longer than its
800-char mobile summary.) Listings below --threshold (default 95%) are flagged MISMATCH.

Outputs: data/mobile_vs_desc_match.csv  (all listings, worst match first)
Usage:   python3 ebay/scripts/find_mobile_desc_mismatch.py [--threshold=95]
"""
import json, glob, re, os, sys, csv, html, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)
THRESH = float(next((a.split('=')[1] for a in sys.argv if a.startswith('--threshold=')), 95))

MOBILE_SPAN = re.compile(r'property="description"[^>]*>(.*?)</span>', re.S | re.I)
HIDDEN_DIV  = re.compile(r'<div[^>]*display\s*:\s*none[^>]*>.*?</div>', re.S | re.I)
WORD        = re.compile(r"[a-z0-9]+")

def text_of(h):
    h = re.sub(r'<(script|style)\b[^>]*>.*?</\1>', ' ', h, flags=re.S | re.I)
    h = re.sub(r'<!--.*?-->', ' ', h, flags=re.S)
    h = re.sub(r'<[^>]+>', ' ', h)
    return re.sub(r'\s+', ' ', html.unescape(h)).strip()

def words(t):
    return WORD.findall(t.lower())

def main():
    rows = []
    stat = collections.Counter()
    for acct in ('dows', 'ige'):
        for f in glob.glob(p(f'data/{acct}/output/media/*.json')):
            d = json.load(open(f))
            desc = d.get('description') or ''
            iid = d.get('item_id') or os.path.basename(f)[:-5]
            m = MOBILE_SPAN.search(desc)
            mobile_text = text_of(m.group(1)) if m else ''
            visible_text = text_of(HIDDEN_DIV.sub(' ', desc))   # body WITHOUT the mobile block

            mob_w = set(words(mobile_text))
            vis_w = set(words(visible_text))
            if not m or not mob_w:
                status, pct = 'NO_MOBILE', ''
                stat['no_mobile'] += 1
            else:
                matched = len(mob_w & vis_w)
                pct = round(100.0 * matched / len(mob_w), 1)
                if pct < THRESH:
                    status = 'MISMATCH'; stat['mismatch'] += 1
                else:
                    status = 'OK'; stat['ok'] += 1
            missing = sorted(mob_w - vis_w) if m else []
            rows.append({
                'account': acct, 'item_id': iid, 'sku': d.get('sku', ''),
                'title': (d.get('title') or '')[:80], 'status': status, 'match_pct': pct,
                'mobile_words': len(mob_w), 'visible_words': len(vis_w),
                'mobile_words_missing_from_visible': ' '.join(missing[:25]),
                'mobile_text': mobile_text[:300], 'visible_text': visible_text[:300],
            })

    # worst (lowest match) first; NO_MOBILE sorts after numeric, MISMATCH floats to top
    def sortkey(r):
        return (0, r['match_pct']) if isinstance(r['match_pct'], float) else (1, 0)
    rows.sort(key=sortkey)

    out = p('data/mobile_vs_desc_match.csv')
    with open(out, 'w', newline='', encoding='utf-8') as fh:
        wtr = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        wtr.writeheader(); wtr.writerows(rows)

    print(f"threshold={THRESH}%  ->  {out}")
    print(f"  MISMATCH (<{THRESH}%): {stat['mismatch']}")
    print(f"  OK (>= {THRESH}%):     {stat['ok']}")
    print(f"  NO_MOBILE block:      {stat['no_mobile']}")
    print(f"  total listings:       {sum(stat.values())}")

if __name__ == '__main__':
    main()
