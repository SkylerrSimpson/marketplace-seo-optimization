# eBay — Item Specifics Backfill

Fill in the missing **Item Specifics** (eBay "aspects") on every active listing, per category,
to improve Best-Match ranking and conversion. Standalone tooling in this repo, mirroring the
Amazon pipeline. **The full plan + week-by-week schedule is in [`PLAN.md`](PLAN.md).**

## Status

**Phase 0 (connect) — in progress.** SDK installed (`benmorel/ebay-sdk-php` +
`dvicklund/ebay-oauth-php-client`); `scripts/lib/EbayClient.php` + `scripts/check_connection.php`
built. Verified against **eBay sandbox**: OAuth app-token mint ✓ and Taxonomy
`getDefaultCategoryTreeId` ✓ (EBAY_US tree id = `0`). The user-token (refresh-grant) + Trading path
is wired but unverified — it needs a refresh token (a sandbox user token, or the production
per-account refresh tokens from the secrets store). No code has run against production.

```
php ebay/scripts/check_connection.php --account=dows --mode=sandbox   # app token + Taxonomy: green
php ebay/scripts/check_connection.php --all                           # both accounts, production
```

Notes baked into the client: pin Taxonomy to `apiVersion=v1` (the SDK default `v1_beta` 404s as
`[2002] Resource not found`); EBAY_US's category tree id is the string `"0"`, so don't use falsy
checks on it.

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
