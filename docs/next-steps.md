# Next Steps — Phases 3-5 (after boss sign-off)

> **Historical snapshot (2026-06-03).** Superseded — write access was granted and
> `apply_metadata.php` has since run; the Shopify metadata pipeline's write steps are
> live (see root `README.md`'s marketplace status table). Kept for the record of how the
> write-back was planned and gated.

Scope: applying the approved product metadata (SEO description, product_type, image
alt) to Shopify and verifying it. Deliberately ignoring GTIN / feed / reviews work
for now (tracked separately in `geo-seo-strategy.md` / `key-findings.md`).

Status as of 2026-06-03: `phase2_output.csv` reviewed by boss; QA pass complete
(0 non-ASCII, X-piece/X-inch consistent, variant values on size/material parents,
alts + product_types frozen). Awaiting final sign-off.

---

## Phase 3 — Apply to Shopify

### Step 0 — Grant write access (ASR admin action — THE BLOCKER)
The `ADMIN_API_TOKEN` in `.env` is currently read-only (`read_products`). Writing
needs **`write_products`**. Re-authorize the `seo-audit` dev-dashboard app/token with
write scope. Until then, `--apply` returns 403. Nothing else in Phase 3 can run first.

### Step 1 — Writer is ready (`apply_metadata.php`)  ✅ DONE
For each product it sets, idempotently:
- `seo.description` (new meta description)  — via `productUpdate`
- `productType` (only the 23 that were blank) — via `productUpdate`
- featured image `alt` (new_image_alt)        — via `fileUpdate`
Reads `phase2_output.json`. Idempotent (reads current values, skips if already
correct), checks `userErrors` on every call, THROTTLED backoff + leaky-bucket pacing.

### Step 2 — Dry run (read-only, safe)
```
php marketplaces/shopify/scripts/apply_metadata.php
```
Logs exactly what it WOULD change per product. Sends nothing. Review the output.

### Step 3 — Canary (3 products)
```
php marketplaces/shopify/scripts/apply_metadata.php --apply --limit 3
```
Writes 3. Verify in Shopify admin: description, product_type, and image alt all correct.

### Step 4 — Full apply
```
php marketplaces/shopify/scripts/apply_metadata.php --apply
```
Idempotent — skips the 3 from the canary and anything already correct; writes the rest.

> Claude cannot run these (read-only perms + token lacks write scope). User runs them
> once write access is granted.

---

## Phase 4 — Validate downstream (read-only; Claude can do)
Scoped to this metadata work (no Merchant Center / feed):
- Fetch a handful of storefront product pages (asroutdoor.com) and confirm the new
  `<meta name="description">`, the Product JSON-LD, and the featured image `alt`
  render correctly.
- Confirm `product_type` shows on the 23 in admin.
- Optional: run a couple URLs through Google's Rich Results test.

## Phase 5 — Monitor (light, ongoing)
- Periodically check Google Search Console for the new descriptions getting picked up
  (impressions/CTR on those pages); confirm nothing regressed.
- Feed obvious low performers back into a quick Phase 2 re-draft.

---

## Immediate order of operations
1. **ASR:** boss sign-off → re-authorize token with `write_products`.
2. **ASR:** run Step 2 (dry run) → Step 3 (canary) → Step 4 (full apply).
3. **Claude:** Phase 4 validation on the storefront once writes are live.

## Project files for this phase
- `apply_metadata.php` — the Phase 3 writer (dry-run default; --apply; --limit N)
- `phase2_output.json` — what the writer reads (carries gid + featured_media_id)
- `phase2_output.csv` — the boss-facing review sheet
- `assemble_output.php` — regenerates the output from drafts_manual.json / drafts_alt.json (ASCII-folded)
