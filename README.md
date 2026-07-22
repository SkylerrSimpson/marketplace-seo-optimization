# DOWScripts

A catalog / SEO optimization **platform** for the shared product dataset owned by **Irongate Enterprises (IGE)** and **Deals Only Web Store (DOWS)** across all active sales channels — auditing gaps and pushing approved changes back to each marketplace, through a reviewable, auditable pipeline.

It has two halves: a **web app** (`web/`, a Laravel UI + queue + admin that non-devs log into) and the **marketplace CLI tooling** it drives (`marketplaces/<name>/`). The web app never reimplements marketplace logic — it shells out to the scripts. **Read [`ARCHITECTURE.md`](ARCHITECTURE.md) first** for the layout and how the two halves connect.

Marketplaces (Amazon, eBay, Walmart, Shopify) continuously evolve their taxonomy trees. Each leaf node carries required and recommended attributes — descriptions, product type, image alt text, GTINs, structured-data fields, and category-specific properties — that directly affect search ranking, Buy Box eligibility, and AI-agent discoverability.

> **If you're new here:** each marketplace folder (`marketplaces/shopify/`, `marketplaces/ebay/`)
> has its own `README.md` with the full script inventory and run order. Better yet, the
> walkthroughs — `marketplaces/shopify/docs/walkthrough.md` and
> `marketplaces/ebay/docs/walkthrough.md` — each trace one real product/listing through
> every script with actual commands and before/after data. For the web app, start with
> `web/DEPLOYMENT.md`. `amazon/` and `walmart/` don't have tooling yet (see status table below).

---

## Repository layout

```
.
├── README.md                  ← you are here
├── ARCHITECTURE.md            ← the layout + how web/ and marketplaces/ connect (read this first)
├── composer.json / .lock      ← script dependencies (Shopify SDK, eBay SDK + OAuth client, phpdotenv)
├── vendor/                    ← composer install target (gitignored)
├── .env / .env.example        ← script credentials (real .env is gitignored; goes in repo ROOT)
│
├── web/                       ← the DOWScripts web app (Laravel UI + queue + admin). See web/DEPLOYMENT.md
│
├── marketplaces/              ← the CLI tooling the web app drives
│   ├── lib/
│   │   └── bootstrap.php      ← shared: autoload + .env + path constants. Every script requires this.
│   │
│   ├── shopify/               ← Shopify — BUILT (see marketplaces/shopify/README.md)
│   │   ├── rules/product-metadata-rules.md   ← field rules + AI drafting prompt
│   │   ├── scripts/           ← ~40 PHP/Python tools
│   │   └── data/{input,drafts,output}/
│   │
│   ├── ebay/                  ← eBay — BUILT (aspects + descriptions pipelines; images audit-only)
│   │   ├── docs/              ← per-pipeline detail docs (review rules, apply-bridge, description-seo)
│   │   ├── scripts/           ← ~49 PHP/Python tools + scripts/lib/EbayClient.php + AUTHOR_PROMPT.md
│   │   └── data/{dows,ige}/{input,output}/, data/aspects/{catId}.json
│   │
│   ├── amazon/                ← SP-API tooling (notifications work planned next)
│   └── walmart/               ← placeholder (Marketplace API) — planned to mirror eBay's pipeline shape
│
└── docs/                      ← cross-marketplace strategy + research notes
```

---

## Setup

```bash
composer install --ignore-platform-reqs   # see PHP version note below
cp .env.example .env                       # fill in credentials (see the file's comments)
```

`composer.json` declares `"php": ">=8.2"` because of the eBay SDK's stated requirement,
but in practice everything here has run fine on **PHP 8.1.2** with the `curl`, `xml`, and
`mbstring` extensions installed — hence `--ignore-platform-reqs` on install. If you hit an
actual 8.2-only language feature, upgrade PHP; don't assume the constraint is required
just because composer.json says so.

All scripts load config + paths from `marketplaces/lib/bootstrap.php`, so they can be
run from anywhere:

```bash
php marketplaces/shopify/scripts/<script>.php
php marketplaces/ebay/scripts/<script>.php --account=dows
```

The `web/` app runs these same scripts for you from a browser — see `web/DEPLOYMENT.md`
to run it.

---

## Marketplace status

| Marketplace | Status | API |
|---|---|---|
| **Shopify**  | ✅ Built — metadata pipeline + collections + GTIN/MPN + accessibility + video/YouTube SEO, all with working write steps | [Admin GraphQL](https://shopify.dev/docs/api/admin-graphql/latest/queries/products) · [productUpdate](https://shopify.dev/docs/api/admin-graphql/latest/mutations/productUpdate?language=direct-api) |
| **eBay**     | ✅ Built — item aspects (canary + one-item live write both confirmed correct; full-catalog write imminent) + descriptions (fully re-authored both accounts) pipelines; images audit-only, no write step yet | [Trading API](https://developer.ebay.com/devzone/xml/docs/reference/ebay/index.html) (ReviseItem) · [Taxonomy API](https://developer.ebay.com/develop/api/sell/taxonomy_api) |
| **Amazon**   | ✅ Built — SP-API event-notification system (Slack or similar) is next up, needs a PM meeting to scope | [SP-API Listings Items](https://developer-docs.amazon.com/sp-api/reference/listings-items-v2020-09-01) |
| **Walmart**  | ⏳ Planned — same shape of work as eBay (titles, descriptions, aspects, images, SEO), once eBay wraps | [Marketplace API](https://developer.walmart.com/us-marketplace/lang-es/docs/utilities-overview) |

The Shopify pipeline is the original reference implementation; eBay mirrors its shape
(read-only audit/export → author/assemble → reviewable output → guarded write) across
three sub-pipelines instead of one, since aspects/descriptions/images each need their own
grounding data. Walmart should follow the same pattern again.

A closing step across **every** marketplace touched: changes made here also need to be
imported into **Usurper** (the company's internal inventory management platform) so its
own custom attributes match what's actually live — not yet started for any marketplace.

All of the above is CLI-only — fine for a dev, not for a non-dev teammate. **`web/`** is
the Laravel app that wraps these same scripts (not a rewrite) so anyone on the team can
run them from a browser, with per-user accounts, live-write confirmation gates, an admin
area, and scheduled read-only runs. See **`web/DEPLOYMENT.md`** and **`ARCHITECTURE.md`**.

---

## Conventions (for everyone working in here)

- **Credentials only in `.env`** (repo root, gitignored). Never hard-code tokens. Add new
  keys to `.env.example` (placeholder values) so teammates know what's needed.
- **Each marketplace gets its own folder under `marketplaces/`** with `scripts/`, `data/`,
  and a README. Shared logic goes in `marketplaces/lib/`.
- **Reads are safe; writes are guarded.** Audit/export scripts are read-only. Write
  scripts default to a **dry run** (and, for eBay, `VerifyOnly=true` — a real server-side
  validation that still commits nothing) and require an explicit flag (`--apply`/`--live`)
  plus, for eBay's canary script, re-typing the item id to confirm.
- **eBay specifically: never rewrite a listing's variation-defining aspect value**
  (Size/Color/etc. — check `varied_by` in review_sheet.csv). eBay ties sales history to
  the exact value; even a units-only reformat orphans it. See `marketplaces/ebay/README.md` for detail
  — every normalize/merge/write script there already guards this; any new one must too.
- **`data/drafts/` (Shopify) is authored content** (the descriptions/alts we wrote) — treat
  it as source of truth and commit it. `data/input/` and `data/output/` are regenerable.
- Run `php -l` on changed scripts; keep output ASCII-only (see the Shopify assembler).

See **`marketplaces/shopify/README.md`** / **`marketplaces/ebay/README.md`** for each marketplace's run order, and
**`docs/`** for cross-marketplace strategy.
