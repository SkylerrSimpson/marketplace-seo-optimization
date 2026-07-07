# DOWS pre-bulk-write snapshot — 2026-07-07

Full backup of live DOWS eBay listing state, taken immediately before running
`apply_aspects.php --live --confirm=WRITE` across the whole account (the "big write"
approved by Ethan's round-2 handoff). Exists so any listing can be restored to exactly
this state if the write goes wrong.

- `items/{itemId}.json` — 1,257 files, current title/category/aspects per listing, pulled
  fresh via `enrich_listings.php --account=dows --refresh` (Browse API `getItem` /
  `get_items_by_item_group`). 1,256 fetched live; see caveat below for the 1,257th.
- `listings.json`, `enriched_summary.csv`, `category_coverage.csv` — companion rollups from
  the same refresh run.
- `review_sheet.csv` — the approved-value sheet as it stood right before the write (post
  Ethan round-2 merge, post the 28-row invalid-value fix from the 2026-07-07 Slack thread).
- `apply_set.json` — the exact specifics payload the write was about to push, frozen at
  this point.

**Caveat — item `364839375985`** (Productive Fitness Fighthrough Series Work Out Poster,
6 Color variations): the Browse API returned 404 NOT_FOUND on this item, retried once,
same result. Our roster still shows it active with 23 in stock, so this could be a
transient issue or the listing may have genuinely ended/been hidden — unconfirmed either
way. `items/364839375985.json` in this backup is **not a fresh pull** — it's the last
known-good snapshot (restored from git history) rather than empty. Recommend excluding
this item from today's write and checking on it separately before including it in any
future round.
