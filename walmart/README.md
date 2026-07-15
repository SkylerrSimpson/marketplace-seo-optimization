# Walmart — Product Metadata Pipeline

Mirrors the Shopify/eBay implementation: read-only audit/export -> author/assemble ->
reviewable output -> guarded write. US and CA are separate seller accounts/credentials
— US is the higher-priority marketplace.

- SDK: `highsidelabs/walmart-api` (composer). Auth is OAuth2 `client_credentials`,
  handled automatically by `Walmart\Configuration::getAccessToken()` — no manual
  token-refresh step like eBay.
- Docs: https://developer.walmart.com/us-marketplace (US), https://developer.walmart.com/ca-marketplace (CA)
- Canada is migrating to the unified **Global APIs by 2026-07-31** (mandatory —
  legacy CA-specific integration stops working after that date). This SDK's client
  already targets the same host (`https://marketplace.walmartapis.com`) and the same
  `client_credentials` OAuth flow the Global APIs use, so no code change is needed for
  the migration itself — just generate new CA API keys in the Developer Portal and
  drop them into `.env`.
- Credentials: `.env` under the Walmart section — `WALMART_CLIENT_ID_US` /
  `WALMART_CLIENT_ID_CA` (+ `_SECRET`). Not filled in yet — nothing here can hit the
  live API until real creds are added.
- Scripts go in `walmart/scripts/` (client wrapper in `walmart/scripts/lib/`), data in
  `walmart/data/{us,ca}/{input,drafts,output}` (per-country, like eBay's per-account split).
- `lib/bootstrap.php` has `walmart_dir($country, $subdir)` for path resolution.

## Two different Items read surfaces — use the right one

`getAllItems`/`getAnItem`/`getCatalogSearch` (seller inventory-management view) return
`productName` (title) plus identifiers/price/status — **no description field**. For the
title/description audit, use `getSearchResult` (Item Search: `query`/`upc`/`gtin`)
instead — it returns full `Item` objects (`description`, `title`, `images`, `brand`,
`productType`, `customerRating`), matching Walmart's public catalog/search content.
Query it per-item by UPC or GTIN. Cross-checked against a second independent SDK
(mediocre/walmart-marketplace's Node client) which shows the same split, so this isn't
just an artifact of one generated client.

## Aspects/attributes have NO read-back endpoint (verified against live ASR data)

Unlike title/description/images, item aspects (Walmart's ItemSpecifics equivalent —
material, capacity, size, etc., submitted via the Item Setup feed/spec) are **not**
returned by any read endpoint. Verified directly against 50 real ACTIVE+PUBLISHED ASR
items via raw `/v3/items` calls (with and without `includeDetails=true`), a single-item
lookup, and Item Search: `additionalAttributes` was empty on every one (0/50), even
though the generated SDK model has a field for it — the field exists in the schema but
Walmart's real API just doesn't populate it here.

The one exception: **the variant-defining dimension itself** (e.g. `actual_color`) IS
exposed, via `variantGroupInfo.groupingAttributes` on items that have a
`variantGroupId`. That covers the vary-by-safety-critical piece (same rule as eBay:
never rewrite a variant-defining value) even though the full attribute set doesn't
come back.

Net effect: we can't audit "what aspects are currently live" from the API the way we
audit title/description. Options: (a) scrape walmart.com PDP spec tables (many
categories render a "Specifications" table to shoppers — real ground truth, but
10k+ items makes this a real scraping job with its own cost/fragility), or (b) treat
aspects as write-forward only for this first pass — submit a complete, correct
attribute set without trying to diff against unknown current state (Walmart's Item
Setup feed presumably just overwrites, not merges, at the category/attribute level —
confirm this before relying on it). Needs a decision before building the aspects half
of the audit merge (task tracking: "Walmart aspects/SEO title/description audit merge").
