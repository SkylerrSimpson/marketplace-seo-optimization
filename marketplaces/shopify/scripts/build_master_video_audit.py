#!/usr/bin/env python3
"""
READ-ONLY. Master site-wide YouTube-embed audit for ASR Outdoor.

Combines the three surfaces that can hold an embedded video:
  - PRODUCTS  (from product_video_inventory.csv, produced by audit_product_media.php)
  - BLOGS     (from blog_video_inventory.csv, produced by audit_blog_media.php)
  - PAGES     (the hub page scan, hardcoded from the latest crawl — update
               PAGE_EMBEDS by hand if pages with video embeds change)

Emits master_video_audit.csv: one row per (URL x video_id), flags whether the
video is a single-video "home" page (indexable) vs a multi-video page (only the
primary video indexes), and — for product pages — whether the video_id is in the
product-structured-data.liquid VideoObject lookup (SNIPPET_LOOKUP below, kept in
sync by hand). Nothing is written to Shopify.

Usage: python3 marketplaces/shopify/scripts/build_master_video_audit.py
"""
import csv, os, re
from collections import defaultdict

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT  = os.path.join(BASE, "data", "output")

# Video IDs carried in the product VideoObject snippet lookup (case media.external_id).
SNIPPET_LOOKUP = {
    "Xepf8uTnR7w","XdsMWcP7A0g","I52rzwyfliE","OdcvlszXOH0","3KRzsc-stEE","vW6hZTmYAqc",
    "D0Sch7DMcHM","3N-gFD2G-jQ","KLobXEEdf1Q","t9Fc9uBs24Y","O_ithEcabnU",
    "BX_OnDzxXRA","GRGKibzHhNQ","o6ToaVuqtZk","xCIlBiVK-hM","F3nU7PYk2CU",
}

TITLES = {
    "t9Fc9uBs24Y":"Gemstone Paydirt Kit","KLobXEEdf1Q":"12pc Break Your Own Geodes Kit",
    "ae63J6_Z-GA":"Guaranteed Paydirt Bags Showcase","D0Sch7DMcHM":"Best Shovels & Picks",
    "Xepf8uTnR7w":"Top Pickaxe","F3nU7PYk2CU":"30in Plastic Sluice Box Set Up",
    "o6ToaVuqtZk":"Benefits of Rubber Riffle Matting","vW6hZTmYAqc":"34in Aluminum Matting Sluice",
    "OdcvlszXOH0":"50in Sluice Box Set Up","UxDOZeiHAes":"Sluice Box Basics & Matting Recovery",
    "GRGKibzHhNQ":"13in Bucket Classifier (how to)","BX_OnDzxXRA":"Mini 6in Classifier (how to)",
    "xCIlBiVK-hM":"Bobby Bo 6pc Paydirt Kit","5ZUFmtFPgdE":"How to Choose a Gold Pan Type",
    "pXoZAJsz4qo":"How to Season Your Gold Pans","3KRzsc-stEE":"Mini Rubber Pocket Sluice (how to)",
    "I52rzwyfliE":"12in Aluminum Sluice Box (how to)","XdsMWcP7A0g":"Rock Pick Hammer (short)",
    "3N-gFD2G-jQ":"Rock Hounding Kit (short)","O_ithEcabnU":"First Aid Kit (short)",
    "7ezkRpEN58g":"Giveaway Series - Subscribe","Z6YEvO7yjqQ":"Giveaway Week 1 Winners",
    "jMwjvm2061M":"Giveaway Week 2 Winners",
}

# Latest /pages crawl: only the hub holds videos.
PAGE_EMBEDS = {
    "https://asroutdoor.com/pages/gold-panning-videos":
        ["3KRzsc-stEE","5ZUFmtFPgdE","BX_OnDzxXRA","F3nU7PYk2CU","GRGKibzHhNQ","I52rzwyfliE",
         "OdcvlszXOH0","UxDOZeiHAes","o6ToaVuqtZk","pXoZAJsz4qo","vW6hZTmYAqc","xCIlBiVK-hM"],
}

def vid_from(url):
    m = re.search(r'(?:youtu\.be/|embed/)([\w-]+)', url)
    return m.group(1) if m else url

rows = []                      # (surface, url, vid)
page_vidcount = defaultdict(int)

# PRODUCTS
for r in csv.DictReader(open(os.path.join(OUT, "product_video_inventory.csv"))):
    if r["has_external_video"] == "1":
        v = vid_from(r["external_url"])
        rows.append(("product", r["url"], v)); page_vidcount[r["url"]] += 1

# BLOGS
for r in csv.DictReader(open(os.path.join(OUT, "blog_video_inventory.csv"))):
    if r["has_youtube"] == "1":
        for u in r["youtube_urls"].split(" | "):
            if u.strip():
                rows.append(("blog", r["url"], vid_from(u))); page_vidcount[r["url"]] += 1

# PAGES
for url, vids in PAGE_EMBEDS.items():
    for v in vids:
        rows.append(("page", url, v)); page_vidcount[url] += 1

out = []
for surface, url, vid in rows:
    n = page_vidcount[url]
    out.append({
        "surface": surface,
        "url": url,
        "video_id": vid,
        "video_title": TITLES.get(vid, ""),
        "videos_on_page": n,
        "single_video_home": "yes" if n == 1 else "no",   # indexability: ~1 video/page
        "in_product_snippet": ("yes" if vid in SNIPPET_LOOKUP else "NO-GAP")
                              if surface == "product" else "n/a",
    })

out.sort(key=lambda r: (r["surface"], r["url"]))
path = os.path.join(OUT, "master_video_audit.csv")
with open(path, "w", newline="") as f:
    w = csv.DictWriter(f, fieldnames=list(out[0].keys()))
    w.writeheader(); w.writerows(out)

# ---- summary ----
distinct = {r["video_id"] for r in out}
prod_ids = {r["video_id"] for r in out if r["surface"] == "product"}
homes = {r["video_id"] for r in out if r["single_video_home"] == "yes"}
no_home = sorted(distinct - homes)
gaps = [r for r in out if r["in_product_snippet"] == "NO-GAP"]

print(f"Surfaces: {sum(1 for r in out if r['surface']=='product')} product rows, "
      f"{sum(1 for r in out if r['surface']=='blog')} blog rows, "
      f"{sum(1 for r in out if r['surface']=='page')} page rows")
print(f"Distinct videos on site: {len(distinct)}   |  with a single-video home (indexable): {len(homes)}")
print(f"Product videos in snippet lookup: {len(prod_ids)}/{len(prod_ids)} "
      f"({'ALL COVERED' if not gaps else str(len(gaps))+' GAPS'})")
if gaps:
    print("  SNIPPET GAPS:", [g["video_id"] for g in gaps])
print(f"\nVideos with NO single-video home (only on hub/multi-video pages -> will NOT index):")
for v in no_home:
    print(f"  {v:14} {TITLES.get(v,'')}")
print(f"\nWrote {path}  ({len(out)} rows)")
