# Internal Linking Pass — DRAFT plan (review before any push)

Date: 2026-06-29. Goal: strengthen the topic clusters so ranking authority flows to the
money pages and the laggards, and keep shoppers moving deeper. **No changes pushed yet —
this is the plan for review.**

## Current state (audited live 2026-06-29)

Good news: the cluster is already mostly interlinked. Every collection intro carries a
"related collections" link row (4–6 links), and the 3 gold guides (kits / pans / sluice)
link out from the body too. So this is a GAP-CLOSING pass, not a rebuild.

Link model = hub-and-spoke per nav parent:
- **Gold Prospecting** (hub) ↔ gold-panning-kits, gold-pans, sluice-boxes,
  sifters-or-classifiers, gold-panning-accessories, gold-prospecting-bucket-kits
- **Paydirt Kits** (paydirt-kits-1 hub) ↔ paydirt-kits, gemstone-paydirt, geodes
- **Rockhounding** (hub) ↔ rockhounding-kits, rockhounding-accessories
- **Outdoor Gear** (hub) ↔ camping-gear, survival-gear, outdoor-knives, paralace,
  metal-detecting-equipment

Linking rules I'll follow (so we don't over-stuff):
- Keep ~4–6 contextual links per page, all genuinely relevant. No link stuffing.
- Every spoke links UP to its hub; hub links DOWN to its money spokes.
- Point extra links AT pages that need to climb (metal-detecting @ pos 24).
- Anchor text = descriptive collection name, house style `class="link underlined-link"`.

---

## PRIORITY 1 — Rescue metal-detecting-equipment (currently pos ~24, link-starved)

It has only ONE editorial link (up to gold-prospecting) and very few inbound. To climb it
needs relevant INBOUND links from sibling/adjacent pages:

| Add link on this page | → pointing to | Anchor / rationale |
|---|---|---|
| gold-panning-accessories | metal-detecting-equipment | "metal detector sand scoops & accessories" — same prospecting buyer |
| gold-prospecting (hub) | metal-detecting-equipment | add to the related row — detecting is a prospecting method |
| rockhounding-accessories | metal-detecting-equipment | diggers/scoops overlap with rock tools |
| outdoor-gear (hub) | metal-detecting-equipment | already links — keep |

And give metal-detecting-equipment more OUTBOUND (it nearly dead-ends):
- → gold-panning-accessories ("pair your detector finds with gold prospecting gear")
- → outdoor-gear (parent hub)

## PRIORITY 2 — Beef up rockhounding-accessories (only 1 link today)

Add a proper related row:
- → rockhounding (parent hub)
- → rockhounding-kits (sibling)
- → geodes, → gemstone-paydirt (natural rock/mineral cross-sell)
- → metal-detecting-equipment (rock picks / digging tools overlap)  *(also feeds Priority 1)*

## PRIORITY 3 — Fill missing spoke→hub + a few sibling gaps

These pages are missing an UP link to their hub and/or an obvious sibling:

| Page | Add link(s) |
|---|---|
| gold-panning-kits | + gold-panning-accessories, + gold-prospecting (hub) |
| gold-pans | + gold-panning-accessories, + gold-prospecting (hub) |
| sluice-boxes | + gold-panning-accessories (miners moss / matting), + gold-prospecting (hub) |
| sifters-or-classifiers | + gold-panning-kits, + gold-prospecting (hub) |
| gold-prospecting-bucket-kits | + gold-prospecting (hub), + gold-panning-accessories |
| paydirt-kits | + paydirt-kits-1 (hub), + geodes |
| gemstone-paydirt | + paydirt-kits-1 (hub), + paydirt-kits |
| geodes | + paydirt-kits-1 (hub), + paydirt-kits |
| rockhounding-kits | + rockhounding (hub) |

## PRIORITY 4 — Cross-hub bridges (strategic, where buyer intent overlaps)

- gold-prospecting ↔ rockhounding (adjacent hobbies; gemstone-paydirt already bridges)
- gold-panning-accessories ↔ metal-detecting-equipment (covered in P1)

---

## Where these links live + how we'd push

Two surfaces hold the links:
1. **Collection intro "related collections" row** (the collection Description box) — holds
   most existing links. EDITABLE TWO WAYS:
   - (a) I push `descriptionHtml` via API (collectionUpdate, covered by write_products). I'd
     fetch each current intro, surgically add the new `<a class="link underlined-link">`
     links to the related row, and push back — idempotent, no content rewritten.
   - (b) I hand you paste-ready updated rows and you paste them (your usual workflow).
2. **Below-grid guide body** (Custom Liquid, only on kits/pans/sluice) — manual paste only.

Recommendation: do the link adds in the **intro related rows via API option (a)** — it's
~15 small, low-risk edits I can do in one pass with a dry-run preview first, vs 15 manual
pastes. Guides already link well; leave them.

## Scope / effort
- ~15 collection intros touched, ~25 new links total. One script run (dry-run → apply).
- Highest ROI = Priority 1 (metal-detecting). If you want to start minimal, do P1+P2 only
  (5 pages) and measure.

## NOT doing (on purpose)
- No link stuffing / no links to frontpage or new-arrivals (low value).
- No blog edits (no API access; marketing owns content).
- No product-description link edits this pass (collection-level first; revisit if it moves).
