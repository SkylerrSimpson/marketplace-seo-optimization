#!/usr/bin/env python3
"""
READ-ONLY. Build a video -> product/collection/blog mapping CSV for ASR Outdoor.

Reads the local catalog (products_audit.csv from audit_products.php,
collections_phase2.csv), takes the 17 ASR YouTube video IDs (discovered from
the channel page + verified via oEmbed, hardcoded in VIDEOS below), scrapes
each PUBLIC watch page for uploadDate + duration, derives the YouTube
thumbnail URL, and proposes a best-match product (with alternates) or a blog/
collection target. Writes ONLY a local CSV. Nothing is sent to Shopify or YouTube.

Prerequisites: data/output/products_audit.csv (audit_products.php) and
data/output/collections_phase2.csv must exist.

Usage:  python3 marketplaces/shopify/scripts/build_video_map.py
Output: marketplaces/shopify/data/output/video_product_map.csv
"""
import csv, re, sys, os, urllib.request
from html import unescape

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # .../shopify
OUT  = os.path.join(BASE, "data", "output")

# 17 ASR-owned videos (id -> title), verified earlier via YouTube oEmbed.
VIDEOS = [
    ("t9Fc9uBs24Y", "The ASR Outdoor Gemstone Paydirt Kit! Find Shiny Crystals and Cool Gems"),
    ("KLobXEEdf1Q", "Unboxing the New ASR Outdoor 12pc Break Your Own Geodes Kit! Discover the Unique Crystals Inside!"),
    ("ae63J6_Z-GA", "ASR Outdoor Gold Panning UPDATED Guaranteed Paydirt Bags Showcase"),
    ("D0Sch7DMcHM", "The BEST Shovels & Picks for Gold Prospecting/ Panning - ASR Outdoor"),
    ("Xepf8uTnR7w", "Top Gold Prospecting Pickaxe for Gold Panning - ASR Outdoor Gear Tested"),
    ("F3nU7PYk2CU", "ASR Outdoor 30\" Plastic Sluice Box Set Up & Product Features"),
    ("o6ToaVuqtZk", "ASR Outdoor: Benefits of Using Rubber Riffle Matting while Gold Prospecting"),
    ("vW6hZTmYAqc", "ASR Outdoor: 34 Inch Aluminum Rubber Matting Sluice Box Set Up"),
    ("OdcvlszXOH0", "ASR Outdoor 50 inch Sluice Box Set Up Gold Prospecting Equipment"),
    ("UxDOZeiHAes", "ASR Outdoor Sluice Box Set Up Basics and Rubber Matting Recovery"),
    ("GRGKibzHhNQ", "How to Use ASR Outdoor 13 Inch Bucket Classifier Screen Sifting Pans for Gold Panning"),
    ("BX_OnDzxXRA", "How to Use ASR Outdoor Mini 6 Inch Classifier Screens for Gold Panning"),
    ("xCIlBiVK-hM", "ASR Outdoor x Bobby Bo 6pc Paydirt Beginner Gold Panning Kit"),
    ("5ZUFmtFPgdE", "ASR Outdoor: How to Choose the Correct Gold Pan Type for Gold Panning"),
    ("pXoZAJsz4qo", "ASR Outdoor: How to Properly Season Your Gold Pans"),
    ("3KRzsc-stEE", "ASR Outdoor: How To Use The ASR Outdoor Mini Rubber Pocket Sluice Box"),
    ("I52rzwyfliE", "ASR Outdoor: How To Use The ASR Outdoor 12\" Aluminum Sluice Box"),
]

# Tokens too common in this catalog to be useful for matching.
STOP = set("""a an the and or for with to of in on your you up set how use using
asr outdoor gold panning prospecting prospect equipment gear kit kits pan pans
new genuine complete portable beginner intermediate advanced product features
showcase unboxing find best top tested while basics correct properly season type
discover unique inside cool shiny crystals gems setup""".split())

# Collection keyword hints (handle -> trigger tokens)
COLL_HINTS = {
    "sluice-boxes":          {"sluice", "matting", "riffle"},
    "sifters-or-classifiers":{"classifier", "sifting", "screen", "screens"},
    "paydirt-kits":          {"paydirt"},
    "gemstone-paydirt":      {"gemstone"},
    "geodes":                {"geodes", "geode"},
    "gold-pans":             {"pan", "pans"},
    "gold-panning-accessories": {"shovel", "shovels", "pick", "picks", "pickaxe"},
}

def tok(s):
    return [w for w in re.findall(r"[a-z0-9]+", s.lower()) if w not in STOP and len(w) > 1]

def fetch(url, timeout=12):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode("utf-8", "replace")

def yt_meta(vid):
    """Return (upload_date, duration_iso, is_public) scraped from the watch page."""
    try:
        h = fetch(f"https://www.youtube.com/watch?v={vid}")
    except Exception as e:
        return ("", "", f"fetch_error:{e}")
    date = ""
    m = re.search(r'"uploadDate":"(\d{4}-\d{2}-\d{2})', h) or \
        re.search(r'itemprop="datePublished" content="(\d{4}-\d{2}-\d{2})', h) or \
        re.search(r'"publishDate":"(\d{4}-\d{2}-\d{2})', h)
    if m: date = m.group(1)
    dur = ""
    m = re.search(r'"lengthSeconds":"(\d+)"', h)
    if m:
        s = int(m.group(1)); dur = f"PT{s//60}M{s%60}S"
    unlisted = '"isUnlisted":true' in h
    return (date, dur, "unlisted" if unlisted else "public")

def load_catalog():
    prods = []
    with open(os.path.join(OUT, "products_audit.csv")) as f:
        for r in csv.DictReader(f):
            prods.append((r["handle"], r["title"], set(tok(r["title"]))))
    colls = {}
    with open(os.path.join(OUT, "collections_phase2.csv")) as f:
        for r in csv.DictReader(f):
            colls[r.get("handle", "")] = r.get("title", "")
    return prods, colls

def best_products(vtokens, prods, n=3):
    scored = []
    for handle, title, ptoks in prods:
        overlap = vtokens & ptoks
        if not overlap:
            continue
        # weight distinctive nouns + size numbers higher
        score = sum(3 if t in {"sluice","classifier","paydirt","gemstone","geode","geodes",
                               "riffle","matting","pickaxe","shovel","bucket","bobby","pocket"}
                    else (2 if t.isdigit() else 1) for t in overlap)
        scored.append((score, handle, title))
    scored.sort(reverse=True)
    return scored[:n]

def best_collection(vtokens):
    hits = [(len(vtokens & trig), h) for h, trig in COLL_HINTS.items() if vtokens & trig]
    hits.sort(reverse=True)
    return hits[0][1] if hits else ""

def main():
    prods, colls = load_catalog()
    rows = []
    for vid, title in VIDEOS:
        vt = set(tok(title))
        date, dur, status = yt_meta(vid)
        is_howto = bool(re.search(r"\b(how to|benefits of|basics)\b", title.lower()))
        cands = best_products(vt, prods)
        best = cands[0] if cands else (0, "", "")
        alts = " | ".join(f"{h}({s})" for s, h, t in cands[1:]) if len(cands) > 1 else ""
        coll = best_collection(vt)
        # suggested target: how-to -> blog (with supporting product/collection); else product
        if is_howto:
            suggested = "blog"
        elif best[0] >= 4:
            suggested = "product"
        else:
            suggested = "collection" if coll else "product"
        rows.append({
            "video_id": vid,
            "video_url": f"https://youtu.be/{vid}",
            "embed_url": f"https://www.youtube.com/embed/{vid}",
            "title": title,
            "yt_status": status,
            "upload_date": date,
            "duration": dur,
            "thumbnail_url": f"https://i.ytimg.com/vi/{vid}/hqdefault.jpg",
            "kind": "how_to" if is_howto else "product_demo",
            "suggested_target": suggested,
            "best_product_handle": best[1],
            "best_product_title": best[2],
            "match_score": best[0],
            "alt_product_candidates": alts,
            "suggested_collection": coll,
            "CONFIRM_target_handle": "",   # <- human fills/corrects this
            "NOTES": "",
        })
        print(f"  {vid}  date={date or '?':10} dur={dur or '?':8} {status:8} -> "
              f"{rows[-1]['suggested_target']:10} {best[1][:42]} (score {best[0]})")

    cols = list(rows[0].keys())
    path = os.path.join(OUT, "video_product_map.csv")
    with open(path, "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(rows)
    print(f"\nWrote {path}  ({len(rows)} videos)")

if __name__ == "__main__":
    main()
