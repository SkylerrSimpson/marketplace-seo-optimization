# Marketplace SEO Optimization

PHP tooling to audit and improve the shared product dataset owned by **Irongate Enterprises (IGE)** and **Deals Only Web Store (DOWS)** across all active sales channels, and to push approved changes back to each marketplace.

Marketplaces (Amazon, eBay, Walmart, Shopify) continuously evolve their taxonomy trees. Each leaf node carries required and recommended attributes — descriptions, product type, image alt text, GTINs, structured-data fields, and category-specific properties — that directly affect search ranking, Buy Box eligibility, and AI-agent discoverability. This repo tracks those gaps and closes them through a reviewable, auditable pipeline.

---

## Repository layout

```
.
├── README.md                  ← you are here
├── composer.json / .lock      ← dependencies (Shopify SDK + phpdotenv only)
├── vendor/                     ← composer install target (gitignored)
├── .env / .env.example         ← credentials (real .env is gitignored)
│
├── lib/
│   └── bootstrap.php           ← shared: autoload + .env + path constants. Every script requires this.
│
├── docs/                        ← cross-marketplace documentation
│   ├── geo-seo-strategy.md      ← research: how to rank in Google + AI agents (ChatGPT/Gemini/etc.)
│   ├── key-findings.md          ← condensed findings + impact hierarchy
│   ├── next-steps.md            ← Phase 3–5 plan (apply → validate → monitor)
│   └── original-plan.txt        ← the initial 6-phase outline
│
├── shopify/                     ← Shopify implementation (the one that's built)
│   ├── README.md                ← Shopify workflow + run order
│   ├── rules/
│   │   └── product-metadata-rules.md   ← field rules + AI drafting prompt
│   ├── scripts/                 ← the PHP tools (see shopify/README.md)
│   └── data/
│       ├── input/               ← read-only exports pulled from Shopify
│       ├── drafts/              ← authored content (descriptions, alts) — source of truth
│       └── output/             ← assembled review files + audit CSVs
│
├── amazon/   ← placeholder (SP-API)
├── ebay/     ← placeholder (Sell/Taxonomy API)
└── walmart/  ← placeholder (Marketplace API)
```

---

## Setup

```bash
composer install          # installs Shopify SDK + phpdotenv into vendor/
cp .env.example .env       # then fill in credentials (see the file's comments)
```

Requires **PHP >= 8.2**. All scripts load config + paths from `lib/bootstrap.php`, so
they can be run from anywhere:

```bash
php shopify/scripts/<script>.php
```

---

## Marketplace status

| Marketplace | Status                                                       | API                                                                                                                                                                                                  |
| ----------- | ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Shopify** | ✅ Built (metadata pipeline; write step pending write scope) | [Admin GraphQL](https://shopify.dev/docs/api/admin-graphql/latest/queries/products) · [productUpdate](https://shopify.dev/docs/api/admin-graphql/latest/mutations/productUpdate?language=direct-api) |
| **Amazon**  | ⏳ Planned                                                   | [SP-API Listings Items](https://developer-docs.amazon.com/sp-api/reference/listings-items-v2020-09-01)                                                                                               |
| **eBay**    | ⏳ Planned                                                   | [Sell / Taxonomy API](https://developer.ebay.com/develop/api/sell/taxonomy_api)                                                                                                                      |
| **Walmart** | ⏳ Planned                                                   | [Marketplace API](https://developer.walmart.com/us-marketplace/lang-es/docs/utilities-overview)                                                                                                      |

The Shopify pipeline is the reference implementation; new marketplaces should mirror its
shape (read-only audit/export → author/assemble → reviewable output → guarded write).

---

## Conventions (for everyone working in here)

- **Credentials only in `.env`** (gitignored). Never hard-code tokens. Add new keys to
  `.env.example` (placeholder values) so teammates know what's needed.
- **Each marketplace gets its own top-level folder** with `scripts/`, `data/`, and a
  README. Shared logic goes in `lib/`.
- **Reads are safe; writes are guarded.** Audit/export scripts are read-only. Write
  scripts default to a **dry run** and require an explicit `--apply` flag.
- **`data/drafts/` is authored content** (the descriptions/alts we wrote) — treat it as
  source of truth and commit it. `data/input/` and `data/output/` are regenerable.
- Run `php -l` on changed scripts; keep output ASCII-only (see the Shopify assembler).

See **`shopify/README.md`** for the Shopify run order and **`docs/`** for strategy and
the current plan.
