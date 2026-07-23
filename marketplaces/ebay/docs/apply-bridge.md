# The Write-Back Bridge — apply_set → eBay ItemSpecifics

> **Status update:** Stage 1 (`build_apply_set.php`) is built and verified. Stage 2
> has both forms: `marketplaces/ebay/scripts/write_canary_test.php` (canary) and
> `marketplaces/ebay/scripts/apply_aspects.php` (the full-account write — see §4.2,
> which corrects this doc's original transport decision). **DOWS's full-account
> write has since run live** (`apply_aspects_run.csv` shows live writes for 1,171
> listings) — see `marketplaces/ebay/README.md`'s Known Gaps section for current
> status. **IGE has not had a review round yet**, so its write is still pending
> (§6). See `marketplaces/ebay/README.md`'s Pipeline 1 table for the current full
> script list — several steps only sketched below, like the normalize/merge
> scripts, now exist as real files.

This doc explains the entire bridge in enough detail to finish it by hand if
needed. Read it top to bottom once; then use the "**Continue by hand**" boxes as
a checklist.

---

## 0. Where the bridge sits in the whole pipeline

```
  Taxonomy API  ──►  aspect schemas (data/aspects/{cat}.json)
  listings.json ──►  every listing, its sku, category, variations
  items/{id}.json ─► the LIVE item specifics already on each eBay listing
        │
        ▼
  build_review_sheet.php  ─►  review_sheet.csv   (one row per listing × aspect)
        │                         ▲
  ai_review.php --mode=current ────┘  (LLM audit of live values → current_value_checks.csv)
  ai_review.php --mode=deep / proposed_fills*  (proposed values for GAPS)
        │
        ▼
  [ reviewer works through review_sheet.csv, fills approved_value column ]
        │
        ▼
  build_apply_set.php   ─►  apply_set.json  +  apply_preview.csv     ◄── STAGE 1 (this doc)
        │
        ▼
  write_canary_test.php / apply_aspects.php  ─►  eBay ReviseItem      ◄── STAGE 2 (§4)
```

The **review sheet is the master sheet** (see `review-guide.md §0`). The bridge
does **not** re-derive anything — it only collapses the (possibly human-edited)
review sheet into the exact set of specifics to push, then (Stage 2) pushes it.

---

## 1. The single most important rule: the MERGE GUARD

eBay's Trading **`ReviseItem`** (and the LMS/Feed equivalent) **REPLACES THE
ENTIRE `ItemSpecifics` container.** It is not a patch. If a listing currently
has 10 specifics and you send a payload with only the 2 new ones you filled,
eBay deletes the other 8 — including REQUIRED aspects — and the listing can go
into an error/ended state.

Therefore the set we write to each listing must be the **union**:

```
   write_set(listing)
     =   (every current aspect we are KEEPING)
       ∪ (reviewer-approved CHANGES to current aspects)
       ∪ (new fills we are ADDING)
       −  (aspects the reviewer explicitly told us to DELETE)
```

`build_apply_set.php` builds exactly this union per listing. In `apply_set.json`
the `specifics` object **is the complete, final payload** for that listing —
what you send is precisely those key→value pairs, nothing implied, nothing
omitted. **Omission from `specifics` means removal.** That is the guard.

> **Why this can't be skipped:** these are legacy listings not in the Inventory
> API, so there is no field-level merge on eBay's side. The merge happens here,
> in our code, or it doesn't happen at all.

---

## 2. Stage 1 — `build_apply_set.php` (BUILT, VERIFIED)

`php marketplaces/ebay/scripts/build_apply_set.php --account=dows [--threshold=80]`

### 2.1 Input
`marketplaces/ebay/data/{account}/output/review_sheet.csv` — grain is one row per
(listing × aspect). Relevant columns:

| column | meaning |
|---|---|
| `item_id` | the eBay listing id (the write target) |
| `sku`, `category_id`, `title` | listing identity / leaf category (drives schema) |
| `source` | where the row came from: `current` (live on eBay), `variation` (per-child, NOT a parent specific), `usurper*`/`rule`/`default` (deterministic gap fill), `llm` (model-proposed gap fill), `none` |
| `current_value` | the value live on eBay today (only on `source=current` rows) |
| `proposed_value` | suggested value (deterministic fill, or LLM suggestion) |
| `approved_value` | **the reviewer's column** — human decision. Empty until reviewed. |
| `certainty` | 0–100; for LLM rows it's the model's confidence |
| `mode` | `SELECTION_ONLY` (value must be in the allowed list) or `FREE_TEXT` |
| `allowed_values` | pipe-joined allowed list (may be truncated with `...`) |

### 2.2 Value precedence (which value wins for one aspect)

For a **gap** aspect (not currently on the listing):
```
approved_value (human)          > 1. always wins; "DELETE" = decline the fill
deterministic proposed          > 2. source rule/default/usurper* → always applied
llm proposed, certainty≥thresh  > 3. only if unreviewed model is confident enough
otherwise                         → skip (no value invented)
```

For a **current** aspect (already live on eBay):
```
approved_value == "DELETE"      → remove it from the write set (action=delete)
approved_value set, ≠ current   → change to approved (action=change)
approved_value == current        → keep (action=keep)
no approved_value                → KEEP the live value unchanged (action=keep)
```

**Critical correctness rule (already coded):** an LLM suggestion sitting in
`proposed_value` on a `current` row is **never auto-applied over a live value.**
Only a human `approved_value` may change or delete a value that is already on
eBay. The LLM's job on live values is to *flag*, not to *overwrite*. (This is
the whole reason we did the `ai_check_current` audit — to surface suspects for
the reviewer, not to act on them.)

`source=variation` rows are **skipped** — they describe per-child variation
dimensions, which are written through the Variations container, not the
parent's ItemSpecifics. (Stage 2 handles variation listings separately; see
§4.4.)

### 2.3 Output

- **`apply_set.json`** — `{ item_id: { sku, category_id, specifics{aspect:value}, diff{added[],changed[],kept,deleted[]} } }`.
  `specifics` is the exact final payload (the merge-guard union).
- **`apply_preview.csv`** — one row per (listing, aspect):
  `item_id, sku, aspect, action, final_value, from_value, chosen_from, mode, valid, title`.
  This is the human-eyeball artifact: every decision and its provenance.
  - `action` ∈ keep / add / change / delete / skip
  - `chosen_from` ∈ current / approved / approved:DELETE / deterministic / llm≥N / none
  - `valid` = advisory SELECTION_ONLY check (see §2.4)

### 2.4 The `valid` column is ADVISORY only

`allowedOk()` checks a SELECTION_ONLY value against the sheet's `allowed_values`,
but **skips lists that are truncated** (ending `...`) because we can't trust a
partial list. So `valid=NO` is a *hint*, not a gate. **Stage 2 (`write_back.php`)
MUST re-validate every SELECTION_ONLY value against the authoritative
`marketplaces/ebay/data/aspects/{cat}.json` `values[]` before sending** — that file, not the
sheet, is the source of truth for allowed values.

### 2.5 Verified run results (2026-06-22, threshold 80, no approvals yet)

| | listings | specifics to write | keep | add | change | delete | skip | invalid(advisory) |
|---|---|---|---|---|---|---|---|---|
| DOWS | 1257 | 16515 | 13202 | 3315 | 0 | 0 | 4651 | 13 |
| IGE  |  370 |  4443 |  3241 | 1202 | 0 | 0 | 1331 |  1 |

Interpretation:
- **change/delete = 0** was correct *at the time* — no approvals had come back yet,
  so nothing overrode a live value. Once `approved_value` is filled, re-running
  makes these non-zero.
- **All 14 `invalid` rows are `chosen_from=current`** — they are pre-existing
  live eBay values that fail our (partial) snapshot list. **Zero new fills are
  invalid.** The merge guard preserves them untouched; we are not introducing a
  bad value. (They likely fail only because our cached allowed-list is truncated
  or eBay's list shifted.)
- Merge math spot-checked: for a sample listing, `kept + added == count(specifics)`
  and no live aspect was dropped. ✓

> **Continue by hand — Stage 1**
> 1. `php marketplaces/ebay/scripts/build_apply_set.php --account=dows`
> 2. `php marketplaces/ebay/scripts/build_apply_set.php --account=ige`
> 3. Open `apply_preview.csv`, sort by `action`. Sanity-check that every
>    `keep` row's `final_value == from_value`, every `add` has a `chosen_from`
>    of deterministic/approved/llm≥N, and no `change`/`delete` exists unless
>    the reviewer approved it.
> 4. Re-run whenever the review sheet changes (new approvals). It is idempotent.

---

## 3. After the reviewed sheet comes back

1. Drop the edited `review_sheet.csv` back in `marketplaces/ebay/data/{account}/output/`.
2. Re-run `build_apply_set.php` for both accounts.
3. In `apply_preview.csv`, the rows that changed are exactly the ones the reviewer touched:
   - `action=change` → a live value was corrected
   - `action=delete` → `DELETE` was written on a junk live value (e.g. `Year="NEW"`,
     `{CHEMNAME1}` Prop 65 templates, `Material=Plastic` on steel goods)
   - `action=add` with `chosen_from=approved` → a value was supplied for a gap
4. Diff the new `apply_set.json` against the prior one to get the precise blast
   radius before any write.

---

## 4. Stage 2 — the write-back scripts

> This section is the original design spec for a script called `write_back.php`.
> It was ultimately built as two scripts instead — `write_canary_test.php`
> (canary) and `apply_aspects.php` (full-account) — sharing the validation/
> transport logic below via `lib/aspect_writer.php`. The design (re-validation,
> transport, safety rails) is what actually shipped; only the filename differs
> from what's written here.

Signature (as built): `php marketplaces/ebay/scripts/apply_aspects.php --account=dows [--live] [--item=ID] [--limit=N] [--confirm=WRITE]`.
Default (no `--live`) is dry-run: render the exact payload, validate, and
**do not** transmit.

### 4.1 Re-validate (hard gate, not advisory)
For each listing in `apply_set.json`, for each SELECTION_ONLY aspect, confirm
`value ∈ aspects/{category_id}.json values[]`. Also enforce eBay limits: value
≤ 65 chars; respect SINGLE vs MULTI cardinality; respect per-listing max aspects.
Any failure → drop that one aspect (never the whole listing) and log it; a
dropped REQUIRED aspect → quarantine the whole listing for manual fix.

### 4.2 Transport — corrected 2026-07-06: use Trading `ReviseItem` directly

**This section originally said not to use `ReviseItem` and to route through
Sell Feed/LMS instead — that turned out to be wrong and has been reversed.**
Feed/LMS's bulk XML format (see Usurper's own `FeedWriter.php` for the eBay
feed shape, which was checked directly) only supports `ReviseInventoryStatus`
(Quantity/Price/SKU) — it has **no Item Specifics support at all**. It was never
a viable transport for this pipeline. The earlier "edge-blocked" note was about
the legacy `GetSellerList`/`GetItem` Trading calls specifically (see
`enrich_listings.php`/`export_listings.php`, which route around that via Browse
and Sell Feed for reads) — `ReviseItem` itself is reachable and has been used
successfully in production.

Use Trading **`ReviseItem`** directly (`benmorel/ebay-sdk-php`'s
`ReviseItemRequestType`). The merged `specifics` map → `Item.ItemSpecifics`, a
`NameValueListArrayType` wrapping one `NameValueListType` per aspect (MULTI
cardinality = multiple `Value[]` entries — see the value-cardinality gotcha in
`marketplaces/ebay/README.md`, including the compound-allowed-value edge case). Variation
listings: parent ItemSpecifics as above, varied dimensions go in
`Item.Variations.Variation[].VariationSpecifics` (a *repeated* array of
`NameValueListArrayType`, not a single one — easy to get the wrapper type wrong,
see `lib/aspect_writer.php`'s `buildSpecifics()`/`loadAspectSchema()` — the
shared implementation both `write_canary_test.php` and `apply_aspects.php`
build their payload with) — keep parent and per-variation specifics strictly
separate (§2.2). Every `ReviseItem` call should default to `VerifyOnly=true`
(eBay validates server-side, commits nothing) before any real write, exactly
as both scripts do.

### 4.3 Safety rails (must implement before `--apply`)
- **Canary first:** push the smallest safe set — the 4 listings missing a
  REQUIRED aspect (DOWS MPN cats `183175`/`163824`/`116656`; IGE `Type` cat
  `46413`) — confirm they come back clean, then widen.
- **Idempotency:** writing the same merged set twice must be a no-op. Key each
  attempt by `item_id` + a hash of `specifics`; skip if last success matches.
- **Backoff:** exponential retry on eBay 5xx / rate limits; never hammer.
- **Per-listing transaction log:** append `item_id, hash, status, eBay errors,
  timestamp` so a resumed run skips done work and you have an audit trail.
- **Rollback note:** capture each listing's pre-write specifics (we already have
  it in `items/{id}.json`) so any listing can be restored.

### 4.4 Variation listings
`is_group=true` listings have a parent + N children. The parent's ItemSpecifics
come from the apply set (variation rows excluded). The varied dimensions
(`varied_by`) and per-child values must be reconstructed from `listings.json`
variations and written in the Variations container. Treat these as a **second
canary class** — do one group end-to-end before bulk.

---

## 5. Constraints that must never be relaxed
- **DRY-RUN until gated.** No eBay write without prod creds/scope **and**
  management sign-off.
- **Never let an LLM value overwrite a live eBay value.** Only `approved_value`.
- **Always re-validate SELECTION_ONLY against `aspects/{cat}.json`** in Stage 2.
- **Never send a partial ItemSpecifics set** — the merge guard union only.
- **Drop a bad aspect, never a whole listing** (except a dropped REQUIRED →
  quarantine for manual fix).

## 6. Open items / next actions

1. ☑ Reviewed `review_sheet.csv` for DOWS round 1 — done, merged via
   `merge_handoff_approvals.php`, then corrected for a unit-normalization
   contamination bug found afterward (see `marketplaces/ebay/README.md`'s vary-by rule).
2. ☑ Canary write-back proven live on production DOWS via
   `write_canary_test.php` (Trading `ReviseItem`, per §4.2's correction) —
   4 hand-picked test listings, sales history confirmed preserved.
3. ☑ Built the bulk `apply_aspects.php` per §4 (transport corrected to
   `ReviseItem`, not LMS), sharing `write_canary_test.php`'s
   `buildSpecifics()`/vary-by-guard/schema-aware splitting logic via
   `lib/aspect_writer.php` rather than duplicating it.
4. ☑ **Full-account write for DOWS has run** — `apply_aspects_run.csv` shows
   live writes for 1,171 listings.
5. ☐ IGE has not yet had any review round — still needs its own
   `merge_handoff_approvals.php` pass once reviewed.
6. ☐ (optional) deterministic rule-fix for the IGE `Type="standard"` cluster
   (~120 rows) to reduce hand-touching each one individually.
