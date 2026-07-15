#!/usr/bin/env python3
"""
build_mobile_fix_review.py — DRY-RUN before/after sheet for the descriptions whose
hidden mobile block doesn't match the visible body (MISMATCH) or that have no mobile
block at all (NO_MOBILE). The "after" reuses the already-generated standardized
description (build_description_review.php output = the description generator's template),
which fixes the issue (mobile derived from the same intro).

Also stamps the previous mobile-state into description_review.csv reviewer_notes for
those listings (backup kept).

Inputs:  data/mobile_vs_desc_match.csv, data/<acct>/output/description_review.csv,
         data/<acct>/output/media/<id>.json
Output:  data/mobile_desc_fix_review.csv   (MISMATCH first, NO_MOBILE in the back half)
Usage:   python3 ebay/scripts/build_mobile_fix_review.py
"""
import csv, json, os, re, shutil, html

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)
oneLine = lambda s: re.sub(r'\s+', ' ', s or '').strip()
csv.field_size_limit(10_000_000)

MOBILE_SPAN = re.compile(r'property="description"[^>]*>(.*?)</span>', re.S | re.I)
def mobile_text_of(proposed_html):
    """The proposed MOBILE description = text inside the hidden schema span."""
    m = MOBILE_SPAN.search(proposed_html or '')
    if not m:
        return ''
    t = re.sub(r'<[^>]+>', ' ', m.group(1))
    return oneLine(html.unescape(t))

def main():
    flagged = [r for r in csv.DictReader(open(p('data/mobile_vs_desc_match.csv')))
               if r['status'] in ('MISMATCH', 'NO_MOBILE')]

    # index the already-built standardized descriptions (the "after") by item_id
    review = {}
    for acct in ('dows', 'ige'):
        rp = p(f'data/{acct}/output/description_review.csv')
        if os.path.isfile(rp):
            for r in csv.DictReader(open(rp)):
                review[r['item_id']] = r

    # previous-state note per flagged listing (used in BOTH outputs)
    def prev_note(r):
        if r['status'] == 'MISMATCH':
            return (f"Previously MISMATCH ({r['match_pct']}%): the hidden mobile description "
                    f"did not match the visible body — the mobile-only copy was not shown on "
                    f"desktop. Standardized so the mobile summary derives from the same intro.")
        return ("Previously NO mobile description: listing had no hidden schema mobile block. "
                "Standardized template adds a compliant mobile summary.")

    rank = {'MISMATCH': 0, 'NO_MOBILE': 1}
    flagged.sort(key=lambda r: (rank[r['status']],
                                float(r['match_pct']) if r['match_pct'] not in ('', None) else 999))

    out = p('data/mobile_desc_fix_review.csv')
    # mobile before/after sit together; then body before/after; then full HTML.
    cols = ['account', 'item_id', 'sku', 'title', 'mobile_status', 'match_pct',
            'before_mobile_text', 'after_mobile_text',
            'before_visible_text', 'after_description',
            'before_html', 'after_html', 'reviewer_notes', 'approved']
    w = csv.writer(open(out, 'w', newline='', encoding='utf-8'))
    w.writerow(cols)
    written = no_after = 0
    for r in flagged:
        iid = r['item_id']
        rv = review.get(iid, {})
        if not rv.get('proposed_html'):
            no_after += 1
        w.writerow([
            r['account'], iid, rv.get('sku', r.get('sku', '')), rv.get('title', r.get('title', '')),
            r['status'], r['match_pct'],
            oneLine(r.get('mobile_text', '')), mobile_text_of(rv.get('proposed_html', '')),
            oneLine(r.get('visible_text', '')), oneLine(rv.get('new_description', '')),
            oneLine(rv.get('prev_html', '')), oneLine(rv.get('proposed_html', '')),
            prev_note(r), '',
        ])
        written += 1

    # stamp the previous-state note into description_review.csv reviewer_notes
    note_by_id = {r['item_id']: prev_note(r) for r in flagged}
    stamped = 0
    for acct in ('dows', 'ige'):
        rp = p(f'data/{acct}/output/description_review.csv')
        if not os.path.isfile(rp): continue
        rows = list(csv.DictReader(open(rp)))
        fields = rows[0].keys() if rows else []
        for row in rows:
            n = note_by_id.get(row['item_id'])
            if n:
                existing = (row.get('reviewer_notes') or '').strip()
                row['reviewer_notes'] = (existing + ' ' + n).strip() if existing else n
                stamped += 1
        bak = rp + '.premobilefix.bak'
        if not os.path.exists(bak): shutil.copy(rp, bak)
        with open(rp, 'w', newline='', encoding='utf-8') as fh:
            wr = csv.DictWriter(fh, fieldnames=list(fields)); wr.writeheader(); wr.writerows(rows)

    print(f"wrote {out}")
    print(f"  rows: {written}  (MISMATCH first, then NO_MOBILE)")
    print(f"  flagged rows missing an 'after' (no proposed_html): {no_after}")
    print(f"  description_review.csv rows stamped with previous-state note: {stamped}")

if __name__ == '__main__':
    main()
