#!/usr/bin/env python3
"""
READ-ONLY. Build the "replace hosted MP4 with YouTube embed" worklist.

Reads product_video_inventory.csv (the 13 products with self-hosted Shopify
MP4s) and matches each to its likely YouTube counterpart (from the 17 channel
videos). Writes a local checklist CSV + prints a table. No store writes.

Output: shopify/data/output/mp4_replacement_worklist.csv
"""
import csv, os

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT  = os.path.join(BASE, "data", "output")

YT = {
    "t9Fc9uBs24Y": "The ASR Outdoor Gemstone Paydirt Kit! Find Shiny Crystals and Cool Gems",
    "KLobXEEdf1Q": "Unboxing the New ASR Outdoor 12pc Break Your Own Geodes Kit!",
    "OdcvlszXOH0": "ASR Outdoor 50 inch Sluice Box Set Up Gold Prospecting Equipment",
    "3KRzsc-stEE": "How To Use The ASR Outdoor Mini Rubber Pocket Sluice Box",
    "Xepf8uTnR7w": "Top Gold Prospecting Pickaxe for Gold Panning - ASR Outdoor Gear Tested",
    "vW6hZTmYAqc": "ASR Outdoor: 34 Inch Aluminum Rubber Matting Sluice Box Set Up",
    "I52rzwyfliE": "How To Use The ASR Outdoor 12\" Aluminum Sluice Box",
    "D0Sch7DMcHM": "The BEST Shovels & Picks for Gold Prospecting/ Panning",
}

# handle-prefix -> (youtube_id or None, confidence, note)
MATCH = {
    "5lb-rough-brazilian-gemstone-paydirt":      ("t9Fc9uBs24Y", "high", ""),
    "5lb-genuine-tumbled-rocks-and-gemstone":    ("t9Fc9uBs24Y", "high", "same YT video as the Brazilian gemstone page"),
    "12pc-break-your-own-geodes-kit":            ("KLobXEEdf1Q", "high", ""),
    "pocket-sluice-box-for-gold-panning-3-riffle": ("3KRzsc-stEE", "high", ""),
    "gold-mining-compact-magnetic-prospectors-pick-axe": ("Xepf8uTnR7w", "high", ""),
    "50-aluminum-folding-sluice-box":            ("OdcvlszXOH0", "high", ""),
    "aluminum-sluice-box-with-rubber-tpr-riffle": ("vW6hZTmYAqc", "medium", "could instead be the 12\" aluminum video (I52rzwyfliE) - verify which size"),
    "gold-panning-aluminum-mini-sluice-box":     ("I52rzwyfliE", "medium", "could instead be 34\" matting video (vW6hZTmYAqc) - verify"),
    "15pc-complete-backpack-gold-panning-kit":   (None, "none", "no clear match in the 17 YT videos - confirm if a backpack-kit video exists deeper on the channel"),
    "rock-pick-hammer":                          (None, "none", "rock pick hammer != the pick-axe video; maybe covered by 'Best Shovels & Picks' (D0Sch7DMcHM) - your call"),
    "13pc-geology-rock-hounding-kit":            (None, "none", "no rock-hounding video among the 17 pulled - verify channel"),
    "3pc-collapsible-camping-lantern":           (None, "none", "not a gold-prospecting video; no match in the 17 - likely no YT version"),
    "first-aid-kit-in-plastic-waterproof-case":  (None, "none", "not a gold-prospecting video; no match in the 17 - likely no YT version"),
}

def match_for(handle):
    for pref, val in MATCH.items():
        if handle.startswith(pref):
            return val
    return (None, "none", "unmatched")

rows_in = [r for r in csv.DictReader(open(os.path.join(OUT, "product_video_inventory.csv")))
           if r["has_hosted_video"] == "1"]

out_rows = []
for r in rows_in:
    yid, conf, note = match_for(r["handle"])
    dur = r["hosted_duration_ms"]
    secs = f"{int(dur)//1000}s" if dur.isdigit() else "?"
    out_rows.append({
        "product_title": r["title"],
        "product_page_url": r["url"],
        "shopify_admin": f"Products > search '{r['handle'][:40]}' > Media",
        "current_mp4_seconds": secs,
        "current_mp4_url": r["hosted_video_url"],
        "replace_with_youtube_url": f"https://youtu.be/{yid}" if yid else "",
        "replace_with_youtube_title": YT.get(yid, "") if yid else "",
        "match_confidence": conf,
        "notes": note,
    })

# order: high, medium, none
order = {"high": 0, "medium": 1, "none": 2}
out_rows.sort(key=lambda x: order.get(x["match_confidence"], 9))

path = os.path.join(OUT, "mp4_replacement_worklist.csv")
with open(path, "w", newline="") as f:
    w = csv.DictWriter(f, fieldnames=list(out_rows[0].keys()))
    w.writeheader(); w.writerows(out_rows)

for r in out_rows:
    yt = r["replace_with_youtube_url"] or "(no YT match)"
    print(f"  [{r['match_confidence']:6}] {r['product_page_url'].split('/products/')[1][:46]:46} -> {yt}")
print(f"\nWrote {path}  ({len(out_rows)} MP4s)")
