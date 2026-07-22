#!/usr/bin/env python3
import json, re, os

IN = "/home/skylersimpson/asr_php/marketplace-seo-optimization/ebay/data/parent_fill/tasks/agent_judgment.jsonl"
OUTDIR = "/home/skylersimpson/asr_php/marketplace-seo-optimization/ebay/data/parent_fill/answers"
OUT = os.path.join(OUTDIR, "agent_judgment_answers.jsonl")
os.makedirs(OUTDIR, exist_ok=True)

# Aspect category sets
IDENTIFIER = {"MPN", "UPC", "EAN", "GTIN", "Model", "Manufacturer Part Number"}
COLOR_LIKE = {"Color", "Manufacturer Color", "Base Color", "Blade Color",
              "Frame Color", "Lens Color", "Band Color"}
DIMENSION = {"Item Weight", "Item Length", "Item Width", "Item Height",
             "Size", "Capacity", "Voltage", "Blade Length"}

# Known color words to detect wrong-field (theme) data in Color aspect
COLOR_WORDS = {"black","white","red","blue","green","yellow","orange","purple","pink",
               "brown","gray","grey","silver","gold","clear","beige","tan","cream",
               "chrome","multicolor","multi-color","multi color","multi-colored",
               "multicolored","multi","multiple","steel","bronze","navy","teal",
               "maroon","violet","ivory","khaki","olive","camo","camouflage",
               "flat dark earth","sea gold"}

def clean_quotes(s):
    # collapse runs of backslashes before quotes, normalize escaped inches
    s = s.replace('\\"', '"')
    s = re.sub(r'\\+"', '"', s)
    s = re.sub(r'\\+', '', s) if s.count('\\') and '"' not in s else s
    return s.strip()

def title_case(s):
    return " ".join(w.capitalize() if w.islower() else w for w in s.split())

def map_allowed(val, allowed):
    """Return exact allowed string matching val (case/space-insensitive) or None."""
    if not allowed:
        return None
    nv = val.strip().lower().replace("-", " ").replace("  ", " ")
    for a in allowed:
        na = a.strip().lower().replace("-", " ").replace("  ", " ")
        if na == nv:
            return a
    return None

def find_multi_term(allowed):
    for cand in ("Multicolor", "Multi-Color", "Multi Color", "Assorted", "Multicolour"):
        for a in allowed:
            if a.strip().lower() == cand.lower():
                return a
    return None

def trunc(s, n=238):
    return s[:n]

def child_list(t):
    """distinct cleaned child values with counts, preserving order by count desc."""
    out = []
    for c in t["child_values"]:
        out.append((clean_quotes(c["value"]), c["count"]))
    return out

def process(t):
    aspect = t["aspect"]
    allowed = t.get("allowed_values") or []
    is_open = t.get("allowed_is_open", True)
    card = t.get("cardinality") or "SINGLE"
    cur = (t.get("current_value") or "").strip()
    cvals = child_list(t)
    # merge duplicates produced by cleaning, AND case/whitespace variants of the
    # same value (e.g. 18" vs 18\", "Silver" vs "SIlver", "stainless steel" vs
    # "Stainless Steel"). Key on a normalized form; keep the highest-count surface form.
    def norm_key(s):
        return re.sub(r"\s+", " ", s.strip().lower())
    bucket = {}  # key -> {count, forms:{surface:count}}
    order = []
    for v, c in cvals:
        k = norm_key(v)
        if k not in bucket:
            bucket[k] = {"count": 0, "forms": {}}
            order.append(k)
        bucket[k]["count"] += c
        bucket[k]["forms"][v] = bucket[k]["forms"].get(v, 0) + c
    cvals = []
    for k in order:
        # surface form = most common, tie-break to the cleaner (Title/proper) one
        forms = bucket[k]["forms"]
        surface = sorted(forms.items(), key=lambda x: (-x[1], 0 if x[0].istitle() else 1))[0][0]
        cvals.append((surface, bucket[k]["count"]))
    cvals.sort(key=lambda x: -x[1])
    distinct = [v for v, _ in cvals]
    summary = ", ".join("%s(%d)" % (v, c) for v, c in cvals[:8])

    pv = ""
    note = ""
    conf = "low"

    # ---- IDENTIFIERS: legitimately per-child ----
    if aspect in IDENTIFIER:
        dna = map_allowed("Does Not Apply", allowed) if allowed else None
        if is_open or dna or any(a.lower().startswith("does not") for a in allowed):
            pv = dna or "Does Not Apply"
            note = "Set 'Does Not Apply': %s is a per-variation identifier that differs by child (%s). One parent value would be wrong." % (aspect, summary)
            conf = "high"
        else:
            pv = ""
            note = "Left blank: %s varies per child variation (%s) and allowed list has no 'Does Not Apply'." % (aspect, summary)
            conf = "med"
        return pv, trunc(note), conf

    # ---- COLOR-LIKE ----
    if aspect in COLOR_LIKE:
        # detect how many distinct are real colors vs wrong-field (theme) entries
        real = [v for v in distinct if v.strip().lower() in COLOR_WORDS]
        wrong = [v for v in distinct if v.strip().lower() not in COLOR_WORDS]
        if len(distinct) == 1:
            m = map_allowed(distinct[0], allowed) if not is_open else None
            if not is_open:
                if m:
                    pv = m; note = "All children share one color '%s' -> mapped to allowed '%s'." % (distinct[0], m); conf = "high"
                else:
                    mt = find_multi_term(allowed)
                    pv = mt or ""
                    note = "Single child color '%s' has no exact allowed match; %s." % (distinct[0], ("used multi term" if mt else "left blank"))
                    conf = "low"
            else:
                pv = title_case(distinct[0]); note = "All children share one color '%s'." % distinct[0]; conf = "high"
            return pv, trunc(note), conf
        # multiple distinct
        if wrong and len(wrong) >= len(real):
            # data looks like themes/sizes not colors
            mt = find_multi_term(allowed) if not is_open else "Multicolor"
            pv = mt or ""
            note = "ANOMALY: child Color values look like themes/non-colors (%s). Proposed %s for reviewer to confirm." % (summary, ("'%s'" % pv if pv else "blank"))
            conf = "low"
            return pv, trunc(note), conf
        # genuine multiple colors
        if not is_open:
            mt = find_multi_term(allowed)
            pv = mt or ""
            note = "Children carry multiple colors (%s) -> %s." % (summary, ("multi term '%s'" % mt if mt else "no multi term in allowed; left blank"))
            conf = "med" if mt else "low"
        else:
            pv = "Multicolor"
            note = "Children carry multiple colors (%s) -> 'Multicolor'." % summary
            conf = "med"
        return pv, trunc(note), conf

    # ---- DIMENSION / MEASURE ----
    if aspect in DIMENSION:
        if len(distinct) == 1:
            v = distinct[0]
            if not is_open:
                m = map_allowed(v, allowed)
                if m:
                    pv = m; note = "All children share '%s' -> allowed '%s'." % (v, m); conf = "high"
                else:
                    mt_size = None
                    pv = ""
                    note = "All children share '%s' but it has no allowed match (allowed are categorical sizes); left blank." % v
                    conf = "low"
            else:
                pv = v; note = "All children share '%s' for %s." % (v, aspect); conf = "high"
            return pv, trunc(note), conf
        # multiple distinct numeric/dimension values -> varies
        if not is_open:
            # try dominant mapping to a categorical size
            top_v, top_c = cvals[0]
            m = map_allowed(top_v, allowed)
            if m and top_c > t["num_children"] / 2:
                pv = m
                note = "Dominant value '%s' (%d/%d) -> allowed '%s'; minority: %s." % (top_v, top_c, t["num_children"], m, summary)
                conf = "med"
            else:
                pv = ""
                note = "%s varies across children (%s) and no clean dominant allowed match; left blank for human." % (aspect, summary)
                conf = "low"
            return pv, trunc(note), conf
        else:
            pv = ""
            note = "%s genuinely varies by variation (%s); not inventing one parent value." % (aspect, summary)
            conf = "med"
            return pv, trunc(note), conf

    # ---- MULTI cardinality (Set Includes, Features, Tools Included, Suitable For, etc.) ----
    if card == "MULTI":
        if not is_open:
            picks = []
            seen = set()
            # match any allowed term that appears as a token/substring in any child value
            for a in allowed:
                al = a.lower()
                for v in distinct:
                    if al in v.lower() or map_allowed(v, [a]):
                        if a not in seen:
                            picks.append(a); seen.add(a)
                        break
            if picks:
                pv = " | ".join(picks)
                note = "MULTI: union of allowed terms found in children (%s). Children: %s." % (pv, summary)
                conf = "med"
            else:
                pv = ""
                note = "MULTI: no child value maps to the constrained allowed list (children: %s); left blank." % summary
                conf = "low"
            return pv, trunc(note), conf
        else:
            # free-text union of distinct child values
            uniq = []
            seen = set()
            for v in distinct:
                k = v.lower()
                if k not in seen and v:
                    uniq.append(v); seen.add(k)
            pv = " | ".join(uniq)
            note = "MULTI free-text: deduped union of child values (%d distinct)." % len(uniq)
            conf = "med" if len(uniq) > 1 else "high"
            return pv, trunc(note), conf

    # ---- CATEGORICAL (SINGLE) ----
    # Material, Type, Theme, Pattern, Style, Country of Origin, Brand, Department,
    # Character Family, Handle Material, Number in Pack, Number of Items in Set, etc.
    if len(distinct) == 1:
        v = distinct[0]
        if not is_open:
            m = map_allowed(v, allowed)
            if m:
                pv = m; note = "All children share '%s' -> allowed '%s'." % (v, m); conf = "high"
            else:
                mt = find_multi_term(allowed)
                pv = mt or ""
                note = "Single child value '%s' has no exact allowed match; %s." % (v, ("used multi term '%s'" % mt if mt else "left blank for reviewer"))
                conf = "low"
        else:
            pv = title_case(v); note = "All children share '%s'." % v; conf = "high"
        return pv, trunc(note), conf

    # multiple distinct categorical
    top_v, top_c = cvals[0]
    second_c = cvals[1][1] if len(cvals) > 1 else 0
    dominant = top_c > second_c and top_c >= t["num_children"] * 0.5 and len(distinct) > 1
    if not is_open:
        m_top = map_allowed(top_v, allowed)
        if dominant and m_top:
            pv = m_top
            note = "Dominant '%s' (%d/%d) -> allowed '%s'; minority: %s." % (top_v, top_c, t["num_children"], m_top, summary)
            conf = "med"
        else:
            # try mapping the most common that DOES map
            mapped = None
            for v, c in cvals:
                mm = map_allowed(v, allowed)
                if mm:
                    mapped = (mm, v, c); break
            mt = find_multi_term(allowed)
            if mt and len(distinct) > 2 and not (mapped and mapped[2] >= t["num_children"]*0.6):
                pv = mt
                note = "Mixed categorical (%s) -> assorted term '%s'." % (summary, mt)
                conf = "low"
            elif mapped:
                pv = mapped[0]
                note = "Mixed values (%s); most-common mappable '%s' -> '%s'." % (summary, mapped[1], mapped[0])
                conf = "low"
            else:
                pv = ""
                note = "Mixed categorical with no clean allowed match (%s); left blank for reviewer." % summary
                conf = "low"
        return pv, trunc(note), conf
    else:
        if dominant:
            pv = title_case(top_v)
            note = "Dominant value '%s' (%d/%d); minority: %s." % (top_v, top_c, t["num_children"], summary)
            conf = "med"
        else:
            pv = title_case(top_v)
            note = "Mixed values (%s); proposed most-common '%s' (free-text)." % (summary, top_v)
            conf = "low"
        return pv, trunc(note), conf


rows = []
with open(IN) as f:
    for line in f:
        line = line.strip()
        if not line:
            continue
        t = json.loads(line)
        pv, note, conf = process(t)
        rows.append({
            "item_id": t["item_id"],
            "sku": t["sku"],
            "aspect": t["aspect"],
            "proposed_value": pv,
            "reviewer_notes": note,
            "confidence": conf,
        })

with open(OUT, "w") as f:
    for r in rows:
        f.write(json.dumps(r, ensure_ascii=False) + "\n")

# Verification
from collections import Counter
conf_c = Counter(r["confidence"] for r in rows)
filled = sum(1 for r in rows if r["proposed_value"] != "")
blank = sum(1 for r in rows if r["proposed_value"] == "")
empty_notes = sum(1 for r in rows if not r["reviewer_notes"].strip())
print("OUT:", OUT)
print("total:", len(rows))
print("confidence:", dict(conf_c))
print("filled:", filled, "blank:", blank)
print("empty_notes:", empty_notes)
