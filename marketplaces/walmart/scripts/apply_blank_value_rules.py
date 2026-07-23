#!/usr/bin/env python3
"""
apply_blank_value_rules.py -- mark proposed_value='blank_value' for rows where the
attribute is confirmed inapplicable to that item, matching the eBay convention (a
"blank_value" on a current-state row signals "not applicable," not "unreviewed" --
same as marketplaces/ebay/scripts/merge_handoff_approvals.php's handling of reviewer
blank_value answers).

Only applied where BOTH current_value and proposed_value are already blank (never
overwrites real data or an existing Usurper-sourced proposal), and only for
(aspect_name, product_type) combinations verified against REAL ASR product titles in
this session (2026-07-11) -- not guessed from category names alone. Deliberately
narrow: attributes that could plausibly be a genuine missing spec rather than a
mismatch (e.g. "Working Load Limit" on Ropes, "Maximum Range" on Metal Detectors) are
left untouched even though they're also 0%-filled, because 0% fill rate alone doesn't
prove inapplicability -- it can just as easily mean "real gap, needs research."

Verified categories:
  1. Jewelry-making COMPONENT-type attributes (clasps, hooks, bails, bead board/bar/
     loom/reamer, beading needle, earring components, etc.) on ASR's beading/jewelry-
     making product types -- confirmed by title check these are metal-testing
     solutions, marking stamps, scoops, and paracord kits, not actual jewelry
     component supplies.
  2. Autograph/collectible-authentication attributes on ASR's toy/model/poster
     product types -- confirmed these are new mass-market toys, not vintage
     autographed collectibles.
  3. Diamond/gemstone GRADING attributes (cut, clarity, carats, fluorescence, etc.)
     on ASR's gem-adjacent product types -- confirmed these are rough/tumbled
     paydirt kits, costume jewelry, and jewelry tools (scales, cleaners, sizers), not
     graded fine jewelry.
  4. Walmart's internal photo-studio-partner workflow fields -- not customer specs at
     all; ASR isn't enrolled in that program for any product.

Usage: python3 walmart/scripts/apply_blank_value_rules.py --country=us
Rewrites the current aspects review file in place (same file matching logic as
match_usurper_aspects.py) -- no version bump, since this doesn't touch any row with
real data, only fills genuinely-blank cells.
"""

import argparse
import csv
import glob
import json
import os

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

JEWELRY_COMPONENT_TYPES = {
    "Loose Bead Type", "Bead Reamer Type", "Jewelry Head & Eye Pin Type",
    "Jewelry Making Transfer Sheet Type", "Bead Board Type", "Melting Point",
    "Jewelry Bail Type", "Earring Component Type", "Jewelry Making Embellishment Type",
    "Jewelry Pin Back Type", "Jewelry Crimp Tube, Cover & End Type",
    "Jewelry Stringing Material Type", "Jewelry Casting Machine Type",
    "Jewelry Clasp & Hook Type", "Beading Needle Type",
    "Jewelry Jump Ring & Split Ring Type", "Jewelry Spacer & Link Type",
    "Needle Size", "Air Flow", "Bead Bar & Aligner Type",
    "Jewelry Making Chain Type", "Chain Type", "Jewelry Making Metal Blank Type",
    "Bead Loom Type",
}
JEWELRY_COMPONENT_PTYPES = {
    "Other Beading & Jewelry Making Supplies", "Jewelry Making & Beading Kits", "Bead Reamers",
}

COLLECTIBLE_ATTRS = {
    "Autographed by", "Autograph Format", "Autograph Authentication Number",
    "Autograph Authentication Company", "Autographed Collectible Type",
    "Era", "Edition", "Memorabilia Type",
}
COLLECTIBLE_PTYPES = {
    "Action Figure Sets", "Action Figures", "Doll Playsets", "Dolls",
    "Model Building Kits", "Other Worn Fashion Accessories", "Play Vehicles",
    "Posters", "Vehicle Playsets", "Doll Accessories", "General Purpose Batteries",
    "Manuals & Guides", "Other Backpacking & Camping Supplies",
}

GEMSTONE_GRADING_ATTRS = {
    "Diamond Color", "Number of Diamonds", "Primary Stone Weight - Carats",
    "Diamond Cut Grade", "Total Gemstone Weight", "Stone Treatment",
    "Diamond Clarity", "Stone Depth Percentage", "Gemstone Clarity",
    "Gemstone Cut", "Total Stone Weight - Carats", "Stone Fluorescence",
    "Birthstone Month", "Stone Polish", "Number of Gemstones", "Certifying Agent",
}
GEMSTONE_GRADING_PTYPES = {
    "Loose Gemstones", "Bracelets", "Rings", "Mixed Piece Jewelry Sets",
    "Gem Scales", "Jewelry Cleaner", "Ring Sizers",
}

PHOTO_PROGRAM_ATTRS = {
    "Front End Photo Partner", "Photo Order Quantity Tier",
    "Photo Configuration Attribute Names", "Photo Accessory Item SKU",
}
# applies to ALL product types -- Walmart-internal workflow field, not a customer spec


def is_blank_value_candidate(aspect_name, product_type):
    if aspect_name in PHOTO_PROGRAM_ATTRS:
        return True
    if aspect_name in JEWELRY_COMPONENT_TYPES and product_type in JEWELRY_COMPONENT_PTYPES:
        return True
    if aspect_name in COLLECTIBLE_ATTRS and product_type in COLLECTIBLE_PTYPES:
        return True
    if aspect_name in GEMSTONE_GRADING_ATTRS and product_type in GEMSTONE_GRADING_PTYPES:
        return True
    return False


def find_review_file(output_dir, country):
    canonical = os.path.join(output_dir, f"aspects_review_{country}.csv")
    if os.path.isfile(canonical):
        return canonical
    candidates = glob.glob(os.path.join(output_dir, "*aspects_review*.csv"))
    if not candidates:
        raise SystemExit(f"no aspects review file found in {output_dir}")
    return max(candidates, key=os.path.getmtime)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--country", default="us")
    args = ap.parse_args()
    country = args.country.lower()

    input_dir = os.path.join(REPO_ROOT, "walmart", "data", country, "input")
    output_dir = os.path.join(REPO_ROOT, "walmart", "data", country, "output")

    with open(os.path.join(input_dir, "listings.json")) as fh:
        listings = json.load(fh)
    product_type_of = {l["sku"]: l.get("productType", "") for l in listings}

    review_path = find_review_file(output_dir, country)
    print(f"reading: {review_path}")
    with open(review_path, newline="") as fh:
        rows = list(csv.DictReader(fh))

    marked = 0
    for r in rows:
        if r["current_value"] or r["proposed_value"]:
            continue  # never touch a row that already has real data or a proposal
        ptype = product_type_of.get(r["sku"], "")
        if is_blank_value_candidate(r["aspect_name"], ptype):
            r["proposed_value"] = "blank_value"
            note = "not applicable to this product"
            r["reviewer_notes"] = (r["reviewer_notes"] + " | " + note) if r["reviewer_notes"] else note
            marked += 1

    with open(review_path, "w", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=[
            "sku", "wpid", "title", "varied_by", "aspect_name", "requirement_level",
            "current_value", "proposed_value", "approved_value", "reviewer_notes",
        ])
        w.writeheader()
        w.writerows(rows)

    print(f"\ndone: {marked} rows marked blank_value -> {review_path}")


if __name__ == "__main__":
    main()
