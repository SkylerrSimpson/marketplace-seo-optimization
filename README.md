# Marketplace SEO Optimization

PHP tooling to audit and improve the shared product dataset owned by **Irongate Enterprises (IGE)** and **Deals Only Web Store (DOWS)** across all active sales channels, and to push approved changes back to each marketplace.

Marketplaces (Amazon, eBay, Walmart, Shopify) continuously evolve their taxonomy trees. Each leaf node carries required and recommended attributes — descriptions, product type, image alt text, GTINs, structured-data fields, and category-specific properties — that directly affect search ranking, Buy Box eligibility, and AI-agent discoverability. This repo tracks those gaps and closes them through a reviewable, auditable pipeline.

> **If you're new here:** each marketplace folder (`shopify/`, `ebay/`) has its own
> `README.md` with the full script inventory and run order — start there, this file is
> just the map. Better yet, start with the walkthroughs — `shopify/docs/walkthrough.md`
> and `ebay/docs/walkthrough.md` each trace one real product/listing through every
> script with actual commands and actual before/after data, which is a faster way in
> than the reference tables. `amazon/` and `walmart/` don't have tooling yet (see status
> table below).

---

## Repository layout

```
.
├── README.md                  ← you are here
├── composer.json / .lock      ← dependencies (Shopify SDK, eBay SDK + OAuth client, phpdotenv)
├── vendor/                    ← composer install target (gitignored)
├── .env / .env.example        ← credentials (real .env is gitignored; goes in repo ROOT, not a subfolder)
│
├── lib/
│   └── bootstrap.php          ← shared: autoload + .env + path constants. Every script requires this.
│
├── docs/                      ← cross-marketplace documentation
│   ├── geo-seo-strategy.md    ← research: how to rank in Google + AI agents (ChatGPT/Gemini/etc.)
│   ├── key-findings.md        ← condensed findings + impact hierarchy
│   ├── next-steps.md          ← Phase 3–5 plan (apply → validate → monitor)
│   ├── video-indexing-runbook.md  ← the Shopify video/YouTube SEO work-stream, narrated
│   ├── enhancement-fixes-plan.md
│   └── original-plan.txt      ← the initial 6-phase outline
│
├── shopify/                   ← Shopify — BUILT (see shopify/README.md)
│   ├── README.md              ← full script inventory + run order, by work-stream
│   ├── rules/product-metadata-rules.md   ← field rules + AI drafting prompt
│   ├── scripts/                ← ~43 PHP/Python tools
│   └── data/{input,drafts,output}/
│
├── ebay/                      ← eBay — BUILT (aspects + descriptions pipelines; images audit-only)
│   ├── README.md               ← full script inventory + run order, by pipeline
│   ├── PLAN.md                 ← superseded original planning doc, kept for history
│   ├── docs/                   ← per-pipeline detail docs (review rules, apply-bridge, description-seo, media-audit)
│   ├── scripts/                 ← ~31 PHP/Python tools + lib/EbayClient.php + AUTHOR_PROMPT.md
│   ├── tools/description-generator.html  ← the canonical manual description-HTML template
│   ├── handoff/                 ← round-trip CSVs exchanged with the human reviewer
│   └── data/{dows,ige}/{input,output}/, data/aspects/{catId}.json, data/for_ethan/
│
├── amazon/   ← placeholder (SP-API) — notifications work planned next, see multi-week roadmap
├── walmart/  ← placeholder (Marketplace API) — planned to mirror the eBay pipeline shape
│
└── dowscripts/   ← planned: a Laravel UI wrapper around all of the above, for non-devs.
                     Not started — see dowscripts/PLAN.md before touching this folder.
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

All scripts load config + paths from `lib/bootstrap.php`, so they can be run from
anywhere:

```bash
php shopify/scripts/<script>.php
php ebay/scripts/<script>.php --account=dows
```

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

Separately: all of the above is CLI-only, which is fine for a dev but not for a non-dev
teammate. **`dowscripts/`** is a planned Laravel web UI that wraps these same scripts (not
a rewrite) so anyone on the team can run them from a browser. Planning only, not started
— see **`dowscripts/PLAN.md`**.

---

## Conventions (for everyone working in here)

- **Credentials only in `.env`** (repo root, gitignored). Never hard-code tokens. Add new
  keys to `.env.example` (placeholder values) so teammates know what's needed.
- **Each marketplace gets its own top-level folder** with `scripts/`, `data/`, and a
  README. Shared logic goes in `lib/`.
- **Reads are safe; writes are guarded.** Audit/export scripts are read-only. Write
  scripts default to a **dry run** (and, for eBay, `VerifyOnly=true` — a real server-side
  validation that still commits nothing) and require an explicit flag (`--apply`/`--live`)
  plus, for eBay's canary script, re-typing the item id to confirm.
- **eBay specifically: never rewrite a listing's variation-defining aspect value**
  (Size/Color/etc. — check `varied_by` in review_sheet.csv). eBay ties sales history to
  the exact value; even a units-only reformat orphans it. See `ebay/README.md` for detail
  — every normalize/merge/write script there already guards this; any new one must too.
- **`data/drafts/` (Shopify) is authored content** (the descriptions/alts we wrote) — treat
  it as source of truth and commit it. `data/input/` and `data/output/` are regenerable.
- Run `php -l` on changed scripts; keep output ASCII-only (see the Shopify assembler).

See **`shopify/README.md`** / **`ebay/README.md`** for each marketplace's run order, and
**`docs/`** for cross-marketplace strategy.
