#!/usr/bin/env python3
"""
extract_description_source.py — build the per-listing GROUNDING source pack the
description authors (LLM re-author pass) work from. Read-only; no eBay writes.

For every listing it pulls EVERYTHING the original holds so nothing is lost in the
rewrite — the author may only add / correct / enhance from this material:
  title, price, aspects (eBay item specifics), short_description (eBay summary),
  narrative paragraphs (the prose <p> body, boilerplate stripped),
  feature_bullets (the factual LABEL: detail bullets, from BOTH <li> AND <td>
  cells — the originals keep features in a specs <td> table, which the old
  extractor missed), and the first image URL.

Inputs:  data/<acct>/output/media/<id>.json     (audit_media.php)
         data/<acct>/output/items/<id>.json     (enrich_listings.php; aspects fallback)
         data/<acct>/output/apply_set.json      (build_apply_set.php; the best-known
                                                  MERGED aspect set per listing — kept
                                                  current + human-approved + new fills.
                                                  Preferred over items/<id>.json's raw,
                                                  pre-review export when present.)
Output:  data/<acct>/output/desc_source_pack.jsonl   (one grounding row/listing)
Usage:   python3 marketplaces/ebay/scripts/extract_description_source.py
"""
import json, glob, os, re, html, csv

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)
oneLine = lambda s: re.sub(r'\s+', ' ', s or '').strip()

# nav / policy / promo chrome that is not real product copy
BOILER = re.compile(
    r'\b(our (e?bay )?store|about us|contact us|add (to )?favou?rite|sign ?up|'
    r'newsletter|feedback|return policy|shipping policy|warranty information|'
    r'view (more|other|all)|msrp|terms|policies?|see (all|more)|home ?page|'
    r'store categories|powered by|subscribe|early access|up to \d+% off|'
    r'fast (and )?free shipping|30[- ]day return)\b', re.I)
NAV_ONLY = re.compile(r'^(returns?|shipping|payment|home|store|menu)$', re.I)

# identifier codes — gibberish in prose, belong in specs only
IDENT = re.compile(
    r'\b(MPN|UPC|EAN|GTIN|SKU|ISBN|Part\s*(?:No\.?|Number|#))\b\s*[:#]?\s*'
    r'[A-Za-z0-9][A-Za-z0-9._/\-]*\.?', re.I)

def strip_chrome(h):
    h = re.sub(r'<(script|style)\b[^>]*>.*?</\1>', ' ', h, flags=re.S | re.I)
    h = re.sub(r'<!--.*?-->', ' ', h, flags=re.S)
    return h

def cell_text(m):
    t = re.sub(r'<[^>]+>', ' ', m)
    return oneLine(html.unescape(t))

def feature_bullets(h):
    """Factual LABEL: detail bullets from <li> AND <td> cells, boilerplate filtered."""
    out, seen = [], set()
    for m in re.finditer(r'<(li|td)[^>]*>(.*?)</\1>', h, re.S | re.I):
        t = cell_text(m.group(2))
        if not t:
            continue
        wc = len(t.split())
        if wc < 3 or wc > 45:          # nav labels too short; paragraphs too long for a bullet
            continue
        if BOILER.search(t) or NAV_ONLY.match(t):
            continue
        k = t.lower()
        if k in seen:
            continue
        seen.add(k)
        out.append(t)
    return out

def narrative(h, bullets):
    """Prose <p> paragraphs that are real description copy (not bullets/boilerplate)."""
    bset = {b.lower() for b in bullets}
    paras = []
    for m in re.finditer(r'<p[^>]*>(.*?)</p>', h, re.S | re.I):
        t = cell_text(m.group(1))
        if len(t.split()) < 8:         # promo one-liners (SUBSCRIBE, © 2026, Up to 90% Off)
            continue
        if BOILER.search(t):
            continue
        if t.lower() in bset:
            continue
        paras.append(t)
    # de-dup while preserving order (some templates repeat the lead paragraph)
    seen, uniq = set(), []
    for t in paras:
        k = t.lower()
        if k in seen:
            continue
        seen.add(k)
        uniq.append(t)
    return uniq

def visible_text(h):
    t = re.sub(r'<[^>]+>', ' ', h)
    return oneLine(html.unescape(t))

def main():
    for acct in ('dows', 'ige'):
        apply_set = {}
        asp = p(f'data/{acct}/output/apply_set.json')
        if os.path.isfile(asp):
            apply_set = json.load(open(asp))

        out = p(f'data/{acct}/output/desc_source_pack.jsonl')
        n = 0
        enriched = 0
        with open(out, 'w', encoding='utf-8') as fh:
            for f in sorted(glob.glob(p(f'data/{acct}/output/media/*.json'))):
                d = json.load(open(f))
                iid = str(d.get('item_id') or os.path.basename(f)[:-5])

                entry = apply_set.get(iid)
                if entry and entry.get('specifics'):
                    aspects = entry['specifics']
                    enriched += 1
                else:
                    item = {}
                    ip = p(f'data/{acct}/output/items/{iid}.json')
                    if os.path.isfile(ip):
                        item = json.load(open(ip))
                    aspects = item.get('aspects') if isinstance(item.get('aspects'), dict) else {}
                raw = d.get('description') or ''
                h = strip_chrome(raw)
                bullets = feature_bullets(h)
                paras = narrative(h, bullets)
                sd = oneLine(d.get('short_description') or '')
                pack = {
                    'item_id': iid,
                    'account': acct,
                    'title': oneLine(d.get('title') or ''),
                    'price': d.get('price') or '',
                    'aspects': {k: v for k, v in aspects.items() if str(v).strip()},
                    'short_description': sd,
                    'narrative': paras,
                    'feature_bullets': bullets,
                    'image': (d.get('images') or [{}])[0].get('url', '') if d.get('images') else '',
                    'full_visible_text': visible_text(h)[:4000],
                }
                fh.write(json.dumps(pack, ensure_ascii=False) + '\n')
                n += 1
        print(f'{acct}: wrote {n} source packs ({enriched} from apply_set.json, '
              f'{n - enriched} from raw items/ export) -> {out}')

if __name__ == '__main__':
    main()
