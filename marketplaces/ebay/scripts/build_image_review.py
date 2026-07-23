#!/usr/bin/env python3
"""
build_image_review.py — DRY-RUN image remediation worklist from the media audit.
NO eBay writes. Turns media_summary.csv + media_images.csv into a per-listing review
sheet of the image problems worth fixing, with the offending URLs and a recommended
action, plus blank approved / reviewer_notes columns.

Problems flagged (priority order):
  HIGH  self-hosted (non-EPS) images  -> re-host on eBay Picture Services (break risk + no zoom)
  MED   images under 800px long edge  -> replace with >=1600px source (eBay zoom needs 800+)
  LOW   images 800-1599px             -> below ideal 1600px, optional upgrade
        very few images (<3)          -> add images

Outputs: data/<acct>/output/image_review.csv
Usage:   python3 marketplaces/ebay/scripts/build_image_review.py
"""
import csv, os, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def p(*a): return os.path.join(ROOT, *a)
ZOOM, IDEAL, FEW = 800, 1600, 3

def main():
    for acct in ('dows', 'ige'):
        summ = {r['item_id']: r for r in csv.DictReader(open(p(f'data/{acct}/output/media_summary.csv')))}
        # collect offending URLs / counts per listing from the per-image data
        self_urls = collections.defaultdict(list)
        lowres_urls = collections.defaultdict(list)
        belowideal = collections.Counter()
        for r in csv.DictReader(open(p(f'data/{acct}/output/media_images.csv'))):
            iid = r['item_id']
            is_eps = (r.get('is_eps', '').strip().lower() in ('1', 'true', 'yes'))
            if not is_eps:
                self_urls[iid].append(r['url'])
            try:
                # long edge = the longer of width/height, matching audit_media.php's
                # own below_zoom_800/below_ideal definitions (previously this used
                # min(), the shorter side, which mis-tiers any non-square image)
                mn = max(int(float(r['width'])), int(float(r['height'])))
            except Exception:
                mn = 0
            if mn and mn < ZOOM:
                lowres_urls[iid].append(f"{r['url']} ({r['width']}x{r['height']})")
            elif mn and mn < IDEAL:
                belowideal[iid] += 1

        out = p(f'data/{acct}/output/image_review.csv')
        w = csv.writer(open(out, 'w', newline='', encoding='utf-8'))
        w.writerow(['item_id', 'sku', 'title', 'image_count', 'all_eps', 'non_eps_images',
                    'min_long_px', 'below_zoom_800', 'below_ideal', 'priority', 'issue',
                    'recommended_action', 'self_hosted_urls', 'low_res_urls',
                    'approved', 'reviewer_notes'])
        rows = []
        for iid, s in summ.items():
            ic   = int(s.get('image_count') or 0)
            non  = int(s.get('non_eps_images') or 0)
            bz   = len(lowres_urls.get(iid, []))      # images < 800px (counted from per-image data)
            bi   = belowideal.get(iid, 0)             # images 800-1599px
            issues, actions = [], []
            if non:
                issues.append(f'{non} self-hosted (non-EPS)')
                actions.append(f'Re-upload {non} image(s) to eBay Picture Services (EPS)')
            if bz:
                issues.append(f'{bz} under 800px (no zoom)')
                actions.append(f'Replace {bz} image(s) with >=1600px source')
            if bi and not bz:
                issues.append(f'{bi} under 1600px (below ideal)')
                actions.append(f'Optional: upgrade {bi} image(s) to >=1600px')
            if ic < FEW:
                issues.append(f'only {ic} image(s)')
                actions.append('Add product images')
            if not issues:
                continue
            priority = 'HIGH' if non else ('MED' if bz else 'LOW')
            rows.append([iid, s.get('sku', ''), s.get('title', ''), ic,
                         s.get('all_eps', ''), non, s.get('min_long_px', ''), bz, bi,
                         priority, '; '.join(issues), '; '.join(actions),
                         ' | '.join(self_urls.get(iid, [])),
                         ' | '.join(lowres_urls.get(iid, [])), '', ''])
        # worst first: HIGH, then MED, then LOW; within, most non-EPS images first
        rank = {'HIGH': 0, 'MED': 1, 'LOW': 2}
        rows.sort(key=lambda r: (rank[r[9]], -int(r[5]), -int(r[7])))
        w.writerows(rows)

        pr = collections.Counter(r[9] for r in rows)
        print(f"{acct}: {len(rows)} listings need image work  "
              f"(HIGH={pr['HIGH']} MED={pr['MED']} LOW={pr['LOW']})  -> {out}")

if __name__ == '__main__':
    main()
