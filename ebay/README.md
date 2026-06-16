# eBay — Item Specifics Backfill

Fill in the missing **Item Specifics** (eBay "aspects") on every active listing, per category,
to improve Best-Match ranking and conversion. Standalone tooling in this repo, mirroring the
Amazon pipeline. **The full plan + week-by-week schedule is in [`PLAN.md`](PLAN.md).**

## Status

Planning. The connection approach is settled (reuse the company's existing eBay app via the
`benmorel/ebay-sdk-php` SDK + OAuth); the read/audit scripts get built in Week 1. No code has
run against production yet.

## Layout

```
ebay/
├── PLAN.md                     # the authoritative plan (read this)
├── data/
│   ├── dows/ {input,drafts,output}/   # per-account (DOWS = "DealsOnly")
│   ├── ige/  {input,drafts,output}/   # IGE = "Irongate Enterprises"
│   └── aspects/  {categoryId}.json    # committed Taxonomy aspect schemas
└── scripts/                    # built in Week 1 (P0–P5)
```

## Stack (decided)

- **SDK:** `benmorel/ebay-sdk-php` + `dvicklund/ebay-oauth-php-client` (the company standard,
  as used by Usurper). Bundles Taxonomy, Trading, Feed/LMS, OAuth.
- **Auth:** OAuth — app token (Taxonomy), per-account user token + refresh (Trading/Feed).
- **Credentials:** the existing company app's production keys, copied into `.env` (see
  `.env.example`, `EBAY_API_*` schema). No new dev-app registration.
- **Write path (later):** bulk Feed/LMS, not per-item `ReviseItem`.
