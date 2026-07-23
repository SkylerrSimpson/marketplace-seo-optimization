#!/usr/bin/env python3
"""
match_usurper_aspects.py -- fill aspects_review_us.csv's proposed_value column from
a Usurper inventory export (marketplaces/walmart/data/{country}/input/InventoryExport_*.csv).

Usurper already carries 148 "_walmart"-suffixed attribute columns (e.g.
attr.color_walmart, attr.count_per_pack_walmart) that correspond closely to Walmart's
own real attribute xml names -- matched here by normalizing both sides (lowercase,
strip non-alphanumerics) and comparing, plus a short list of manually-resolved
aliases for real semantic matches that don't normalize-match automatically
(numberofpieces_walmart -> pieceCount, and "*value_walmart" companion columns as
fallbacks for their already-matched base column). Non-aspect columns (pricing,
promos, system/export status, identifiers already tracked elsewhere, structural
variant fields, and feature01-05/keyfeatures -- confirmed to hold Key Features
marketing bullet copy, not the short discrete "Additional Features" spec attribute,
see EXCLUDE_BASE_NAMES) are explicitly excluded, not guessed at.

Matching rule (deliberately conservative, confirmed with the user 2026-07-11):
  - SINGLE-value aspects (exactly one row for that sku+aspect in aspects_review.csv):
    propose Usurper's value whenever it's non-blank AND differs from current_value
    AFTER NUMERIC NORMALIZATION (Usurper exports counts as floats -- "1.0000" vs
    Walmart's "1" -- which isn't a real disagreement; both sides get compared as
    floats when both parse as numbers, so only genuine value differences surface).
  - MULTI-value aspects (more than one row already, e.g. 6 filled "Accessories
    Included" rows): SKIPPED. Reconciling a partial Usurper list against an
    already-populated multi-row list is ambiguous; only genuinely single-row (blank
    or single-value) aspects get auto-filled from Usurper.

Usage: python3 marketplaces/walmart/scripts/match_usurper_aspects.py --country=us
Rewrites: marketplaces/walmart/data/{country}/output/aspects_review_{country}.csv (proposed_value
  column filled in; reviewer_notes gets "Usurper: <source column>" appended -- the
  Usurper attribute name, not the value, since the value is already visible in
  proposed_value -- when the proposal is a correction to a non-blank current_value,
  so a reviewer can judge trustworthiness by source (e.g. count_walmart is known to
  carry some stale 0.0000 placeholders, so seeing "Usurper: count_walmart" is a cue
  to double-check that specific proposal))
"""

import argparse
import csv
import glob
import json
import os
import re

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Non-aspect columns: pricing/promo, system/export status, identifiers tracked
# elsewhere, structural/variant fields, catalog-level (not aspect) fields.
# feature01-05/keyfeatures: CONFIRMED (2026-07-11, against real Usurper data for
# SON-FOLDPOCKKNF-BLU) to hold full "TITLE - sentence" marketing bullet copy, i.e.
# the same content as Walmart's Key Features field on the DESCRIPTION side, not
# Walmart's short discrete "Additional Features" spec attribute. Originally
# (wrongly) aliased to "features" -- pulled that alias after seeing full paragraphs
# land in an aspect meant for values like "Magnetic"/"Heavy-Duty". Belongs to the
# catalog/description review, not this aspects sheet.
EXCLUDE_BASE_NAMES = {
    "price", "min_price", "sale_price",
    "promo_end", "promo_end2", "promo_end3",
    "promo_start", "promo_start2", "promo_start3",
    "promo_price", "promo_price2", "promo_price3",
    "promo_type", "promo_type2", "promo_type3",
    "exported", "last_errors",
    "upc", "mpn", "manufacturer_part_number", "manufacturerpartnumber",
    "condition", "description", "shortdescription", "title",
    "variantgroupid", "isprimaryvariant",
    "variation_attribute_1", "variation_attribute_2", "variation_attribute_3",
    "feature01", "feature02", "feature03", "feature04", "feature05", "keyfeatures",
}

# Manually resolved aliases: usurper base name -> walmart xml name. Only for real
# semantic matches that don't normalize-match automatically (verified against
# product_type_specs_us.json before adding, not guessed).
MANUAL_ALIASES = {
    "numberofpieces": "pieceCount",
    "recommendeduse": "recommendedUses",   # legacy singular alias for recommended_uses
    "colorvalue": "color",
    "patternvalue": "pattern",
    "themevalue": "theme",
    "charactervalue": "character",
    "targetaudiencevalue": "targetAudience",
}


def norm(s):
    return re.sub(r"[^a-z0-9]", "", s.lower())


def values_equal(a, b):
    """Exact match, or both parse as the same number (Usurper exports counts as
    floats -- '1.0000' -- which isn't a real disagreement with Walmart's '1')."""
    if a == b:
        return True
    try:
        return float(a) == float(b)
    except (TypeError, ValueError):
        return False


def build_column_mapping(walmart_xml_names):
    """usurper .csv header (attr.X_walmart) -> walmart xml name, or None to skip."""
    walmart_norm = {norm(x): x for x in walmart_xml_names}
    mapping = {}
    for base_norm, target in MANUAL_ALIASES.items():
        mapping[base_norm] = target

    return walmart_norm, mapping


def resolve_target(base, walmart_norm, alias_map):
    if base in EXCLUDE_BASE_NAMES:
        return None
    n = norm(base)
    if n in alias_map:
        return alias_map[n]
    if n in walmart_norm:
        return walmart_norm[n]
    return None


def find_review_file(output_dir, country):
    """
    Locate the current aspects review sheet, whatever it's actually named --
    build_aspects_review.py writes aspects_review_{country}.csv, but it may have been
    renamed (e.g. to a versioned walmart_{country}_aspects_review_v1.csv) as part of a
    handoff workflow. Prefer the canonical name; otherwise take the most recently
    modified file matching *aspects_review*.csv so we don't silently operate on stale
    data.
    """
    canonical = os.path.join(output_dir, f"aspects_review_{country}.csv")
    if os.path.isfile(canonical):
        return canonical
    candidates = glob.glob(os.path.join(output_dir, "*aspects_review*.csv"))
    if not candidates:
        raise SystemExit(f"no aspects review file found in {output_dir} -- run build_aspects_review.py first")
    return max(candidates, key=os.path.getmtime)


def next_version_path(review_path):
    """'..._v1.csv' -> '..._v2.csv'; if no version suffix, append '_v2' before writing
    (so the input we read from a versioned file is never overwritten in place --
    matches the v1/v2 convention already used for the eBay description sheets)."""
    m = re.search(r"_v(\d+)\.csv$", review_path)
    if m:
        n = int(m.group(1)) + 1
        return review_path[:m.start()] + f"_v{n}.csv"
    return review_path[:-4] + "_v2.csv"


def find_usurper_file(input_dir):
    candidates = sorted(glob.glob(os.path.join(input_dir, "InventoryExport_*.csv")))
    if not candidates:
        raise SystemExit(f"no InventoryExport_*.csv found in {input_dir}")
    return candidates[-1]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--country", default="us")
    args = ap.parse_args()
    country = args.country.lower()

    input_dir = os.path.join(REPO_ROOT, "walmart", "data", country, "input")
    output_dir = os.path.join(REPO_ROOT, "walmart", "data", country, "output")

    with open(os.path.join(output_dir, f"product_type_specs_{country}.json")) as fh:
        specs_by_type = json.load(fh)  # {productType: {xml_name: title}}
    walmart_xml_names = set()
    for attrs in specs_by_type.values():
        walmart_xml_names.update(attrs.keys())
    walmart_norm, alias_map = build_column_mapping(walmart_xml_names)

    with open(os.path.join(input_dir, "listings.json")) as fh:
        listings = json.load(fh)
    product_type_of = {l["sku"]: l.get("productType", "") for l in listings}
    active_skus = set(product_type_of.keys())

    usurper_path = find_usurper_file(input_dir)
    print(f"using Usurper export: {usurper_path}")

    with open(usurper_path, newline="", encoding="utf-8-sig") as fh:
        header = next(csv.reader(fh))
    col_target = {}  # csv column name -> walmart xml name
    for col in header:
        if not col.startswith("attr.") or not col.endswith("_walmart"):
            continue
        base = col[len("attr."):-len("_walmart")]
        target = resolve_target(base, walmart_norm, alias_map)
        if target:
            col_target[col] = target
    print(f"usable Usurper columns mapped to real Walmart aspects: {len(col_target)}")

    # --- pass 1: read Usurper export, collect values only for active SKUs ----------
    usurper_values = {}  # sku -> {xml_name: (value, source_col)}  (first non-blank source wins per xml_name)
    matched_skus = 0
    with open(usurper_path, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            sku = row.get("sku")
            if sku not in active_skus:
                continue
            matched_skus += 1
            bucket = usurper_values.setdefault(sku, {})
            for col, target in col_target.items():
                val = (row.get(col) or "").strip()
                if not val:
                    continue
                if target not in bucket:  # first non-blank source wins (primary before alias)
                    bucket[target] = (val, col)  # keep the source column for reviewer_notes

    print(f"Usurper rows matched to an active US SKU: {matched_skus}/{len(active_skus)}")

    # --- pass 2: build sku -> {title: xml_name} reverse lookup (per that sku's own
    # product type, since the same title can map to different xml names across types
    # in theory -- keeping it per-sku avoids cross-type collisions).
    def reverse_map_for(sku):
        ptype = product_type_of.get(sku, "")
        attrs = specs_by_type.get(ptype, {})
        rev = {}
        for xml_name, title in attrs.items():
            rev.setdefault(title, xml_name)  # first wins on a title collision
        return rev

    # --- pass 3: read aspects_review.csv, count rows per (sku, aspect_name) to know
    # which aspects are single-row (eligible) vs multi-row (skip, ambiguous).
    review_path = find_review_file(output_dir, country)
    print(f"reading aspects review from: {review_path}")
    with open(review_path, newline="") as fh:
        rows = list(csv.DictReader(fh))
    row_count_by_key = {}
    for r in rows:
        key = (r["sku"], r["aspect_name"])
        row_count_by_key[key] = row_count_by_key.get(key, 0) + 1

    filled = 0
    corrected = 0
    for r in rows:
        sku = r["sku"]
        key = (sku, r["aspect_name"])
        if row_count_by_key.get(key, 0) != 1:
            continue  # multi-value aspect with existing data -- skip, ambiguous
        u_vals = usurper_values.get(sku)
        if not u_vals:
            continue
        rev = reverse_map_for(sku)
        xml_name = rev.get(r["aspect_name"])
        if xml_name is None or xml_name not in u_vals:
            continue
        proposed, source_col = u_vals[xml_name]
        current = r["current_value"]
        if values_equal(proposed, current):
            continue
        r["proposed_value"] = proposed
        source_name = source_col[len("attr."):] if source_col.startswith("attr.") else source_col
        if current:
            note = f"Usurper: {source_name}"
            r["reviewer_notes"] = (r["reviewer_notes"] + " | " + note) if r["reviewer_notes"] else note
            corrected += 1
        else:
            filled += 1

    out_path = next_version_path(review_path)
    with open(out_path, "w", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=[
            "sku", "wpid", "title", "varied_by", "aspect_name", "requirement_level",
            "current_value", "proposed_value", "approved_value", "reviewer_notes",
        ])
        w.writeheader()
        w.writerows(rows)

    print(f"\ndone: {filled} blanks filled, {corrected} corrections flagged (proposed_value != current_value) -> {out_path}")
    print(f"(input file {review_path} left untouched)")


if __name__ == "__main__":
    main()
