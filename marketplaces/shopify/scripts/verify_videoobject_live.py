#!/usr/bin/env python3
"""
READ-ONLY. Fetch live product pages and verify the deployed VideoObject JSON-LD.

For each product that has an external (YouTube) embed, fetch the storefront page,
extract every <script type="application/ld+json"> block, find the VideoObject(s),
and check:
  - a VideoObject is present
  - embedUrl/thumbnailUrl point at the expected YouTube video_id
  - uploadDate is present AND matches the real scraped date (not the publish-date
    fallback) for IDs we curated
  - duration is present for curated IDs
  - the JSON parses

Prerequisites: audit_product_media.php must have run to produce
data/output/product_video_inventory.csv.

Usage:
  python3 marketplaces/shopify/scripts/verify_videoobject_live.py                 # all external-video products
  python3 marketplaces/shopify/scripts/verify_videoobject_live.py <url> [<url>..] # just these
Writes data/output/videoobject_live_check.csv when run over the full set.
"""
import csv, json, os, re, sys, time, urllib.request

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT  = os.path.join(BASE, "data", "output")

# Real (curated) upload dates from the snippet lookup — used to confirm the page
# is emitting curated data, not the product.published_at fallback.
CURATED = {
    "Xepf8uTnR7w":("2023-09-21","PT1M34S"),"XdsMWcP7A0g":("2025-04-10","PT36S"),
    "I52rzwyfliE":("2022-06-15","PT1M45S"),"OdcvlszXOH0":("2022-06-21","PT4M25S"),
    "3KRzsc-stEE":("2022-06-15","PT1M33S"),"vW6hZTmYAqc":("2022-06-22","PT2M57S"),
    "D0Sch7DMcHM":("2023-09-25","PT3M21S"),"3N-gFD2G-jQ":("2025-09-26","PT25S"),
    "KLobXEEdf1Q":("2024-09-13","PT2M59S"),"t9Fc9uBs24Y":("2024-09-24","PT1M30S"),
    "O_ithEcabnU":("2026-02-24","PT23S"),"BX_OnDzxXRA":("2022-06-16","PT3M16S"),
    "GRGKibzHhNQ":("2022-06-16","PT2M34S"),"o6ToaVuqtZk":("2022-06-24","PT49S"),
    "xCIlBiVK-hM":("2022-06-16","PT4M3S"),"F3nU7PYk2CU":("2022-06-28","PT2M33S"),
}

def fetch(u):
    return urllib.request.urlopen(
        urllib.request.Request(u, headers={"User-Agent": "Mozilla/5.0"}), timeout=25
    ).read().decode("utf-8", "replace")

def vid_from(s):
    m = re.search(r'(?:youtu\.be/|embed/|vi/)([\w-]+)', s)
    return m.group(1) if m else ""

def jsonld_blocks(html):
    out = []
    for m in re.finditer(r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
                         html, re.S | re.I):
        raw = m.group(1).strip()
        try:
            out.append((json.loads(raw), None))
        except Exception as e:
            out.append((None, f"{e}"))
    return out

def check(url, expected_vid):
    try:
        html = fetch(url)
    except Exception as e:
        return {"url": url, "expected_vid": expected_vid, "status": "FETCH_ERR", "detail": str(e)}
    blocks = jsonld_blocks(html)
    parse_errs = [d for _, d in blocks if d]
    videos = [b for b, _ in blocks if isinstance(b, dict) and b.get("@type") == "VideoObject"]
    if not videos:
        return {"url": url, "expected_vid": expected_vid, "status": "NO_VIDEOOBJECT",
                "detail": f"{len(blocks)} ld+json blocks, {len(parse_errs)} parse errors"}
    # match the VideoObject for the expected video
    vo = next((v for v in videos if vid_from(v.get("embedUrl","")+v.get("thumbnailUrl","")) == expected_vid), videos[0])
    got_vid = vid_from(vo.get("embedUrl","") + " " + vo.get("thumbnailUrl",""))
    up, dur = vo.get("uploadDate",""), vo.get("duration","")
    problems = []
    if got_vid != expected_vid: problems.append(f"vid {got_vid}!={expected_vid}")
    if not up: problems.append("no uploadDate")
    if expected_vid in CURATED:
        exp_up, exp_dur = CURATED[expected_vid]
        if up[:10] != exp_up: problems.append(f"uploadDate {up}!=curated {exp_up} (fallback?)")
        if dur != exp_dur: problems.append(f"duration {dur or '-'}!=curated {exp_dur}")
    if parse_errs: problems.append(f"{len(parse_errs)} ld+json parse error(s)")
    return {"url": url, "expected_vid": expected_vid,
            "status": "PASS" if not problems else "CHECK",
            "got_vid": got_vid, "uploadDate": up, "duration": dur,
            "n_videoobjects": len(videos), "detail": "; ".join(problems)}

def load_all():
    rows = []
    for r in csv.DictReader(open(os.path.join(OUT, "product_video_inventory.csv"))):
        if r["has_external_video"] == "1":
            rows.append((r["url"], vid_from(r["external_url"])))
    return rows

def main():
    args = [a for a in sys.argv[1:] if a.startswith("http")]
    if args:
        inv = {r["url"]: vid_from(r["external_url"])
               for r in csv.DictReader(open(os.path.join(OUT, "product_video_inventory.csv")))
               if r["has_external_video"] == "1"}
        targets = [(u, inv.get(u, "?")) for u in args]
        write = False
    else:
        targets = load_all(); write = True
    results = []
    for i, (url, vid) in enumerate(targets):
        res = check(url, vid)
        results.append(res)
        flag = {"PASS":"OK  ", "CHECK":"CHK ", "NO_VIDEOOBJECT":"MISS", "FETCH_ERR":"ERR "}.get(res["status"], "??? ")
        print(f"  [{flag}] {res.get('got_vid', res['expected_vid']):12} {url.split('/products/')[-1][:46]:46} {res.get('detail','')}")
        time.sleep(1.6)  # be gentle on the storefront
    npass = sum(1 for r in results if r["status"] == "PASS")
    print(f"\n{npass}/{len(results)} PASS")
    if write and results:
        path = os.path.join(OUT, "videoobject_live_check.csv")
        cols = ["url","expected_vid","status","got_vid","uploadDate","duration","n_videoobjects","detail"]
        with open(path, "w", newline="") as f:
            w = csv.DictWriter(f, fieldnames=cols, extrasaction="ignore")
            w.writeheader(); w.writerows(results)
        print(f"Wrote {path}")

if __name__ == "__main__":
    main()
