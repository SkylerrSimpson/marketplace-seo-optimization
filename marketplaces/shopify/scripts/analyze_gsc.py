#!/usr/bin/env python3
"""
READ-ONLY. Analyze Google Search Console exports to find where ASR Outdoor's
traffic comes from and the highest-leverage CTR + impression opportunities.

Reads the GSC export bundle in data/output/:
  Chart.csv, Countries.csv, Devices.csv, Pages.csv, Queries.csv,
  Search appearance.csv, Filters.csv

Outputs a console dashboard + three opportunity worklists:
  ctr_opportunity_pages.csv     - pages ranking well but under-clicked -> rewrite title/meta
  ctr_opportunity_queries.csv   - queries ranking well but under-clicked -> SERP copy / intent
  ranking_opportunity_queries.csv - page-2 queries (pos 11-20) w/ impressions -> push to page 1

Method: compares each row's actual CTR to an expected organic CTR-by-position
curve; "opportunity clicks" = impressions x (expected_ctr - actual_ctr) when the
page already ranks in reach (pos <= ~15). That isolates clicks you can win WITHOUT
ranking higher (a title/meta job) vs. clicks that need a ranking push.
"""
import csv, os, re

OUT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + "/data/output"

# Blended organic CTR by position (decimal). Interpolated beyond the table.
CTR_CURVE = {1:.285,2:.155,3:.105,4:.078,5:.060,6:.048,7:.039,8:.032,9:.027,10:.024,
             11:.018,12:.016,13:.014,14:.013,15:.012,16:.011,17:.010,18:.009,19:.008,20:.008}
BRAND_RE = re.compile(r'\b(asr|asroutdoor)\b', re.I)

def expected_ctr(pos):
    if pos <= 1: return CTR_CURVE[1]
    if pos >= 20: return CTR_CURVE[20] * (20.0/pos)   # decay past 20
    lo = int(pos); hi = min(lo+1, 20)
    f = pos - lo
    return CTR_CURVE[lo] + (CTR_CURVE[hi]-CTR_CURVE[lo]) * f

def num(s):
    return float(s.replace(',', '').replace('%', '') or 0)

def load(name, key):
    path = os.path.join(OUT, name)
    if not os.path.exists(path): return []
    rows = []
    for r in csv.DictReader(open(path, encoding='utf-8-sig')):
        try:
            rows.append({
                key: r[list(r.keys())[0]],
                "clicks": int(num(r["Clicks"])),
                "impr": int(num(r["Impressions"])),
                "ctr": num(r["CTR"]) / 100.0,
                "pos": num(r["Position"]),
            })
        except (KeyError, ValueError):
            continue
    return rows

def page_type(url):
    if re.search(r'asroutdoor\.com/?$', url): return "home"
    for t in ("products", "collections", "blogs", "pages"):
        if f"/{t}/" in url: return t[:-1] if t != "blogs" else "blog"
    return "other"

def pct(n, d): return f"{(100.0*n/d):.2f}%" if d else "-"

def bar(title): print(f"\n{'='*64}\n{title}\n{'='*64}")

# ---------------------------------------------------------------- load
pages   = load("Pages.csv", "url")
queries = load("Queries.csv", "query")
devices = load("Devices.csv", "device")
countries = load("Countries.csv", "country")
appear  = load("Search appearance.csv", "appearance")
chart   = []
cp = os.path.join(OUT, "Chart.csv")
if os.path.exists(cp):
    for r in csv.DictReader(open(cp, encoding='utf-8-sig')):
        chart.append((r["Date"], int(num(r["Clicks"])), int(num(r["Impressions"]))))

tot_clicks = sum(p["clicks"] for p in pages)
tot_impr   = sum(p["impr"] for p in pages)

# ---------------------------------------------------------------- WHERE TRAFFIC COMES FROM
bar("OVERVIEW (last 3 months)")
print(f"  Clicks: {tot_clicks:,}   Impressions: {tot_impr:,}   "
      f"Site CTR: {pct(tot_clicks, tot_impr)}")
if chart:
    first = sum(c for _,c,_ in chart[:len(chart)//2])
    last  = sum(c for _,c,_ in chart[len(chart)//2:])
    trend = "rising" if last > first*1.1 else "falling" if last < first*0.9 else "flat"
    print(f"  Click trend (1st half -> 2nd half): {first} -> {last}  ({trend})")

bar("SEARCH APPEARANCE  (which result type earns the traffic)")
for a in sorted(appear, key=lambda x:-x["impr"]):
    print(f"  {a['appearance']:22} clicks {a['clicks']:>6}  impr {a['impr']:>8,}  "
          f"CTR {a['ctr']*100:5.2f}%  pos {a['pos']:.1f}")

bar("DEVICE  (CTR gaps = template/SERP issues)")
for d in sorted(devices, key=lambda x:-x["impr"]):
    print(f"  {d['device']:10} clicks {d['clicks']:>6}  impr {d['impr']:>8,}  CTR {d['ctr']*100:5.2f}%  pos {d['pos']:.1f}")

bar("TOP COUNTRIES")
for c in sorted(countries, key=lambda x:-x["clicks"])[:6]:
    print(f"  {c['country']:18} clicks {c['clicks']:>6}  impr {c['impr']:>8,}  CTR {c['ctr']*100:5.2f}%")

bar("TOP PAGES BY CLICKS  (your current traffic drivers)")
by_type = {}
for p in pages:
    by_type.setdefault(page_type(p["url"]), [0,0])
    by_type[page_type(p["url"])][0] += p["clicks"]
    by_type[page_type(p["url"])][1] += p["impr"]
for p in sorted(pages, key=lambda x:-x["clicks"])[:12]:
    print(f"  {p['clicks']:>5} clk {p['impr']:>7,} imp {p['ctr']*100:5.2f}% p{p['pos']:4.1f}  "
          f"[{page_type(p['url']):10}] {p['url'][:60]}")
print("  --- clicks by page type ---")
for t, (c, i) in sorted(by_type.items(), key=lambda x:-x[1][0]):
    print(f"    {t:10} {c:>6} clicks  ({pct(c, tot_clicks)} of clicks)  {i:>8,} impr")

bar("BRANDED vs NON-BRANDED QUERIES  (non-branded = real SEO growth)")
b = [q for q in queries if BRAND_RE.search(q["query"])]
nb = [q for q in queries if not BRAND_RE.search(q["query"])]
bc, bi = sum(q["clicks"] for q in b), sum(q["impr"] for q in b)
nc, ni = sum(q["clicks"] for q in nb), sum(q["impr"] for q in nb)
print(f"  Branded:     {bc:>5} clicks ({pct(bc, bc+nc)})   {bi:>8,} impr   CTR {pct(bc,bi)}")
print(f"  Non-branded: {nc:>5} clicks ({pct(nc, bc+nc)})   {ni:>8,} impr   CTR {pct(nc,ni)}")
print("  Top non-branded queries by clicks:")
for q in sorted(nb, key=lambda x:-x["clicks"])[:10]:
    print(f"    {q['clicks']:>4} clk {q['impr']:>7,} imp  p{q['pos']:4.1f}  {q['query'][:50]}")

# ---------------------------------------------------------------- OPPORTUNITIES
def opp_clicks(row, reach=15.0):
    if row["pos"] > reach: return 0.0
    gap = expected_ctr(row["pos"]) - row["ctr"]
    return row["impr"] * gap if gap > 0 else 0.0

# CTR opportunities: ranks in reach, under expected, enough impressions to matter
ctr_pages = [dict(p, opp=opp_clicks(p)) for p in pages if p["impr"] >= 200 and opp_clicks(p) > 0]
ctr_pages.sort(key=lambda x:-x["opp"])
ctr_qs = [dict(q, opp=opp_clicks(q)) for q in queries
          if q["impr"] >= 100 and not BRAND_RE.search(q["query"]) and opp_clicks(q) > 0]
ctr_qs.sort(key=lambda x:-x["opp"])
# Ranking opportunities: page-2 queries with impressions (push pos 11-20 -> page 1)
rank_qs = [q for q in queries if 10.5 <= q["pos"] <= 20.5 and q["impr"] >= 150
           and not BRAND_RE.search(q["query"])]
rank_qs.sort(key=lambda x:-x["impr"])

bar("CTR OPPORTUNITY -> PAGES  (rank well, under-clicked: REWRITE TITLE/META)")
print(f"  {'opp':>5} {'now':>5} {'exp':>5}  clk  impr     pos  page")
for p in ctr_pages[:15]:
    print(f"  +{p['opp']:>4.0f} {p['ctr']*100:4.1f}% {expected_ctr(p['pos'])*100:4.1f}% "
          f"{p['clicks']:>4} {p['impr']:>6,} p{p['pos']:4.1f} [{page_type(p['url'])[:4]}] {p['url'][:52]}")
print(f"  Est. clicks recoverable from top pages: +{sum(p['opp'] for p in ctr_pages[:25]):.0f}/mo-ish")

bar("CTR OPPORTUNITY -> QUERIES  (non-branded, rank well, under-clicked)")
for q in ctr_qs[:15]:
    print(f"  +{q['opp']:>4.0f}  {q['ctr']*100:4.1f}%->{expected_ctr(q['pos'])*100:4.1f}%  "
          f"{q['clicks']:>4} clk {q['impr']:>6,} imp p{q['pos']:4.1f}  {q['query'][:48]}")

bar("RANKING OPPORTUNITY -> PAGE-2 QUERIES  (pos 11-20: push to page 1)")
for q in rank_qs[:15]:
    print(f"  {q['impr']:>6,} imp  {q['clicks']:>3} clk  p{q['pos']:4.1f}  {q['query'][:54]}")

# ---------------------------------------------------------------- write worklists
def write(name, rows, cols):
    path = os.path.join(OUT, name)
    with open(path, "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols, extrasaction="ignore")
        w.writeheader()
        for r in rows:
            w.writerow({**r, "expected_ctr": f"{expected_ctr(r['pos'])*100:.2f}%",
                        "actual_ctr": f"{r['ctr']*100:.2f}%",
                        "opportunity_clicks": f"{r.get('opp',0):.0f}",
                        "page_type": page_type(r["url"]) if "url" in r else ""})
    print(f"  wrote {path} ({len(rows)} rows)")

bar("WORKLISTS WRITTEN")
write("ctr_opportunity_pages.csv", ctr_pages,
      ["url","page_type","clicks","impr","actual_ctr","expected_ctr","pos","opportunity_clicks"])
write("ctr_opportunity_queries.csv", ctr_qs,
      ["query","clicks","impr","actual_ctr","expected_ctr","pos","opportunity_clicks"])
write("ranking_opportunity_queries.csv", rank_qs,
      ["query","clicks","impr","actual_ctr","pos"])
