# eBay Item Specifics Backfill — Implementation Plan

> **SUPERSEDED — kept for history, not current guidance.** This was the original Week-1
> planning doc. The project has since grown two more phases this doc never covers
> (descriptions, images) and reversed one decision this doc makes explicitly (§6/§9 say to
> use bulk Feed/LMS for the write path and avoid Trading `ReviseItem` — Feed/LMS turned out
> not to support Item Specifics at all, so `ReviseItem` is what actually got built and
> tested live). **See [`ebay/README.md`](README.md) for the current, accurate picture.**

> This is the authoritative plan. §0 is the plain-English overview; §1–5 are the strategy +
> technical decisions; **§6 is the day-by-day Week 1 schedule** ("what exactly we're doing");
> §7–10 are scope, risk, dependencies, and definition of done.

---

## 0. In plain English (read this first)

eBay makes you describe each product with a list of structured attributes it calls **Item
Specifics** — e.g. for a kids' bag: Brand, Color, Theme, Pattern, Bag Width/Height/Depth, Fabric
Type. The expected list is **different for every product category**, and eBay's search ranks
listings partly on how completely these are filled. Most of our listings are missing a lot of them.

**Our job: for each listing, find which attributes are missing, fill them in as well as we can
automatically, and push them back to eBay.** Four moves:

1. **Pull** every listing we have, plus the attributes each one already has.
2. **Pull the rulebook** — for each category, eBay's full list of expected attributes + allowed values.
3. **Diff** the two → a per-listing "gap report" of what's missing.
4. **Fill** the gaps from our own product data + AI, then submit them back. eBay's API rejects
   invalid values, so we fix and resubmit; we do **not** hand-check every value (that's downstream's job).

Week 1 (§6) does moves 1–3 in full and prototypes move 4 on a single category. **No changes to real
listings happen this week.**

### Glossary
- **Item Specifics / aspects** — the structured name/value attributes on a listing. Same thing;
  "aspect" is the API's word.
- **Required / Recommended / Optional** — eBay's three aspect tiers per category. Required is mostly
  filled; we target Recommended (ranking-weighted) first, then Optional.
- **Leaf category** — the most specific category a listing sits in; it decides which aspects apply.
- **Taxonomy API** — eBay's API that returns a category's aspect rulebook (names + allowed values).
- **Trading / Feed (LMS) API** — read listings with Trading; bulk-update them with Feed. We read with
  Trading, write with Feed.
- **Usurper** — our company's internal master-data system (a separate app). It's the source of the
  real attribute values we'll fill with, and we reuse its eBay credentials + SDK — but we build here.

---

## 1. The task, precisely

Every eBay listing has **Item Specifics** — name/value pairs eBay calls **aspects**. Each
listing's leaf category defines its own aspect set: **Required**, **Recommended**, **Optional**.
Required is mostly filled today. **Our job: fill the Recommended + Optional aspects across the
whole catalog, per category** — eBay Best-Match ranking weights aspect completeness, so this is
a data-quality + ranking + conversion play. Hard part is scale + taxonomy size, not any one item.

Accounts in scope: **DOWS** (seller "DealsOnly") and **IGE** ("Irongate Enterprises").

## 2. Operating principles

- This is an **engineering** task: gather data, analyze, and **fill gaps to the best of the
  model's ability**. Line-by-line human validation is out of scope (3,600+ products = infeasible).
  This intentionally differs from the hand-authored Shopify approach.
- Fill is driven by a prompt over **{Usurper master data + the listing's own data + the category
  aspect schema (incl. allowed-value lists) + the computed gap list}**.
- **Validation gate = API acceptance.** Submit feeds; if eBay rejects a value, fix + resubmit.
  Factual accuracy of *well-formed* values is owned downstream (marketing / marketplace managers).
- **Known limitation:** "the API accepted it" catches *malformed/off-schema* values, not
  *wrong-but-well-formed* ones. Mitigate cheaply: constrain output to allowed-value lists and prefer
  Usurper-derived values; the residual risk is accepted per the principles above.

## 3. Connection & stack — SOLVED by reusing the company's existing eBay app

We are **not** registering a new dev app or hand-rolling a client. Usurper
(`~/asr_php/usurper`) already runs a full production eBay integration; we mirror its stack in
*our* standalone repo (reference only — we do not import Usurper code):

- **SDK:** `benmorel/ebay-sdk-php` ^19.3 (maintained dts fork; bundles **Taxonomy** with
  `GetItemAspectsForCategory`, **Trading**, **Feed/LMS**, **OAuth**) + `dvicklund/ebay-oauth-php-client`.
  *(Supersedes the earlier "thin Guzzle client" call — there IS a maintained, company-standard SDK.)*
- **Auth:** **OAuth** (app token via client-credentials for Taxonomy; per-account user token +
  refresh for Trading/Feed). NOT Auth'n'Auth — the company is migrating off it.
- **Credentials:** the company app already exists. We copy the **production** `EBAY_API_*` keys
  (DOWS default block / `_IGE` suffix) into our `.env`, sourced from the production secrets store.
  (Usurper's local `.env` has only sandbox; per-seller OAuth tokens live in Usurper's tenant DB.)
- **Write path:** **bulk Feed/LMS** (`createInventoryTask` → `uploadFile` → `getResultFile`) — the
  same bulk-feed model Usurper uses. This **sidesteps the Trading `ReviseItem` full-replace footgun**
  (no read-merge-resend needed when we submit a feed), though we still include current aspects in the
  feed payload to be safe.

## 4. Architecture — mirrors `amazon/`

```
ebay/
├── PLAN.md  README.md
├── data/
│   ├── dows/ {input,drafts,output}/   # per-account (mirrors amazon/data/{account})
│   ├── ige/  {input,drafts,output}/
│   └── aspects/  {categoryId}.json    # committed Taxonomy schemas (the "what's required" truth)
└── scripts/                          # built in Week 1 (§6); none exist yet:
    ├── lib/EbayClient.php             #   wraps benmorel SDK (Taxonomy + Trading + Feed + OAuth)
    ├── check_connection.php   (P0)
    ├── export_listings.php    (P1)
    ├── fetch_category_aspects.php (P4)
    └── audit_listings.php     (P5)
```
Already in place: the per-account `data/` dirs + `data/aspects/`, and `lib/bootstrap.php`
constants `EBAY_DATA`, `EBAY_ASPECTS`, `ebay_dir($account,$sub)`. The scripts above are intentionally
not written yet — they get built on the benmorel SDK during Week 1, not before the connection is proven.

## 5. Phase plan (mirrors the Amazon pipeline in this repo)

| Phase | Script | Does | R/W | Blocked by |
|---|---|---|---|---|
| **0** | `check_connection.php` | OAuth app+user tokens, Taxonomy + Trading ping | R | prod creds |
| **1** | `export_listings.php` | `GetSellerList` (paged) → `input/listings.json` | R | P0 |
| **2** | (folds into P1) | per-item `GetItem` only if P1 specifics incomplete | R | P1 |
| **3** | (replaces ASIN step) | SKU → Usurper master match + match-rate report | R | Usurper dump |
| **4** | `fetch_category_aspects.php` | Taxonomy aspects → committed `data/aspects/*.json` | R | P1 |
| **5** | `audit_listings.php` | diff listing vs schema → `output/aspect_gaps.csv` | R | P1, P4 |
| **6** | *(to build)* `fill_aspects.php` + `submit_feed.php` | AI-fill gaps; guarded Feed/LMS write-back | W | Usurper dump, prod write creds, sign-off |

**Read/analysis half (P0–P5) is fully buildable in Week 1. Fill/write (P6) is deferred** pending
the Usurper data dump + prod write creds — the same dependency that gates the Amazon fill phase.

---

## 6. Week 1 — day-by-day execution

**Gate to start:** plan sign-off + prod eBay credentials in hand + Usurper dump availability
confirmed. Days below assume creds are available; if they slip, Days 1–2 read-only work can run
against sandbox to de-risk the SDK wiring.

Calendar: **Day 1 = Tue 6/16 … Day 5 = Mon 6/22** (skipping the weekend). Each day has a single
headline goal, concrete tasks, a **Deliverable**, and an **Acceptance check** (how we *know* it's done).

### Day 1 (Tue 6/16) — Connect (Phase 0)
- Add `benmorel/ebay-sdk-php` + `dvicklund/ebay-oauth-php-client` to composer; `composer install`.
- Read Usurper's `ClientAdaptor.php`, `AuthorizationService.php`, `EbayCredentialService.php` as the
  reference for token-minting (app token via client-credentials; user-token refresh).
- Build `ebay/scripts/lib/EbayClient.php` wrapping the benmorel SDK (OAuth + Taxonomy + Trading).
  (`.env.example` already uses Usurper's `EBAY_API_*` per-account OAuth schema.)
- Build `check_connection.php`: mint app token → Taxonomy `getDefaultCategoryTreeId` → per-account
  user-token refresh → `GeteBayOfficialTime`.
- **Deliverable:** `check_connection.php --account=dows` and `--account=ige` both all-green.
- **Acceptance:** app token mints; US tree id returned; both accounts' user tokens valid.

### Day 2 (Wed 6/17) — Export listings (Phase 1)
- Implement `export_listings.php` on benmorel Trading `GetSellerList` (paged, `GranularityLevel=Fine`,
  `IncludeVariations`, `DetailLevel=ReturnAll`). Resolve every `// VERIFY` field path against a live
  response. Normalize → `data/{acct}/input/listings.json` + `output/listings.csv`.
- Decide if `GetItem` (P2) is needed (only if `GetSellerList` omits full specifics for big stores).
- Run full export for DOWS, then IGE.
- **Deliverable:** complete `listings.json` per account + a counts summary (total listings, distinct
  leaf categories, listings missing ≥1 recommended aspect).
- **Acceptance:** listing count reconciles with Seller Hub (±expected); 3 random listings' specifics
  match the live eBay page.

### Day 3 (Thu 6/18) — Cache category aspect schemas (Phase 4)
- Implement `fetch_category_aspects.php` on benmorel Taxonomy `getItemAspectsForCategory`. Confirm the
  real response shape (`aspectConstraint`: required/recommended/optional, `aspectMode`
  FREE_TEXT/SELECTION_ONLY, cardinality, `aspectValues` allowed list) and fix the `// VERIFY` parsing
  in `audit_listings.php` to match.
- Cache every distinct category → committed `data/aspects/{categoryId}.json`.
- **Deliverable:** `data/aspects/*.json` for all distinct categories + a one-page "aspect landscape"
  (distinct categories, total aspects, % SELECTION_ONLY vs FREE_TEXT, count required/recommended/optional).
- **Acceptance:** every distinct category from Day 2 has a cached schema; the kids-bag category shows
  the expected real aspects (Theme, Pattern, Bag Width/Height/Depth, Fabric Type, …).

### Day 4 (Fri 6/19) — Audit / gap analysis (Phase 5)
- Implement `audit_listings.php` fully against the confirmed schema shape. Emit `aspect_gaps.csv` per
  account, priority-scored: required-missing > recommended > optional; tag fillability (constrained =
  pick-from-allowed-list vs inference = AI-from-text).
- Produce the **gap-analysis dashboard**: total gaps, gaps by usage, gaps by fillability, top
  categories by gap volume, and a first estimate of Tier-1 (Usurper-derivable) vs inference share.
- **Deliverable:** `aspect_gaps.csv` + written gap analysis that **sizes the whole job** (e.g., "N
  listings × ~M recommended gaps each = X fills; ~Y% constrained/auto-fillable").
- **Acceptance:** numbers reconcile (listings × avg gaps ≈ rows); we can answer "how big is this."

### Day 5 (Mon 6/22) — Fill prototype + write-path spike (Phase 6 design only)
- Design the fill contract: prompt = {Usurper data + listing data + aspect schema + gaps} → proposed
  values **constrained to allowed-value lists**, written to `data/{acct}/drafts/` as a reviewable diff.
- Build a **fill prototype on ONE category** (the kids-bag category): generate proposed aspect values
  for ~10 listings, **dry-run only, zero live writes**.
- Spike the write path: read Usurper's `FeedWriter.php`; write a short design note confirming Feed/LMS
  (`createInventoryTask`/`uploadFile`/`getResultFile`) vs `ReviseFixedPriceItem`.
- **Deliverable:** one-category fill-prototype CSV + a P6 write-path design note + end-of-week summary
  with a go/no-go for full rollout.
- **Acceptance:** prototype emits schema-valid proposed values for one category (every SELECTION_ONLY
  value is in the allowed list); write path decided + documented.

## 7. Out of scope for Week 1 (explicit)

- **No live writes to production listings.** Week 1 ends at a *dry-run* fill prototype.
- No full-catalog fill (deferred to the Usurper dump + sign-off).
- No IGE-specific edge handling beyond running the read pipeline for it.
- No FAQ/marketing copy — aspects only.

## 8. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Prod creds slip past Tue | Days 1–2 SDK wiring runs against sandbox to de-risk; swap creds when they land. |
| Taxonomy aspect JSON shape differs from assumption | Day 3 confirms the live shape before the audit depends on it (`// VERIFY` markers). |
| `GetSellerList` omits full specifics at scale | P2 `GetItem` fallback already planned. |
| Trading/Feed rate limits (~5k/day) | benmorel + Usurper rate-limit patterns; pace + backoff; reads are cheap. |
| SELECTION_ONLY rejects values | Constrain all fills to cached allowed-value lists (Day 5 prototype proves it). |
| Usurper dump not ready | Read pipeline (P0–P5) doesn't need it; only the *real* fill (P6) does — already deferred. |

## 9. Open dependencies & decisions

1. **Prod eBay credentials** — App ID/Cert ID/Dev ID + per-account OAuth refresh tokens (or run the
   OAuth consent via RU_NAME). Confirm the source (production secrets store).
2. **Usurper catalog dump** — format + a DOWS/IGE export for the fill phase (gates P6, not Week 1).
3. **Write path** — Feed/LMS bulk (recommended; matches Usurper) vs `ReviseFixedPriceItem`.
4. **Scope** — Recommended aspects first across the catalog, then Optional.
5. **SDK** — `benmorel/ebay-sdk-php` (company standard, as used by Usurper).

## 10. Definition of done (end of Week 1)

✅ Live, tested connection to both accounts (P0). ✅ Full listings export for DOWS + IGE (P1). ✅
Committed aspect-schema cache for every distinct category (P4). ✅ Priority-scored gap report +
written sizing of the whole job (P5). ✅ Dry-run fill prototype on one category + a decided write
path (P6 design). ✅ Zero production writes. → Ready to scope full fill once the Usurper dump lands.
