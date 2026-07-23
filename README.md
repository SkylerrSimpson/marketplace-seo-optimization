# DOWScripts

Catalog and SEO tooling for the product listings **Irongate Enterprises (IGE)** and
**Deals Only Web Store (DOWS)** sell across Amazon, eBay, Walmart, and Shopify. Each
marketplace pipeline audits listings against that marketplace's own requirements
(required attributes, descriptions, images, identifiers), drafts fixes, and writes
approved changes back through a reviewable, guarded process.

Two halves, one repo:

- **`marketplaces/`** — the CLI scripts that do the actual work (per marketplace).
- **`web/`** — a Laravel app that runs those same scripts from a browser: per-user
  accounts, an admin role, live-write confirmation, scheduled read-only runs, backup
  browsing, and a connection-check widget. It never reimplements marketplace logic —
  it shells out to the scripts in `marketplaces/`.

Read **[`ARCHITECTURE.md`](ARCHITECTURE.md)** for how the two halves connect and deploy
together. Each marketplace folder also has its own `README.md` with the full script
inventory and run order; eBay and Shopify additionally have `docs/walkthrough.md`,
tracing one real product through every step with actual commands and data.

---

## Repository layout

```
.
├── README.md                  ← you are here
├── ARCHITECTURE.md            ← how web/ and marketplaces/ connect
├── composer.json / .lock      ← dependencies for the scripts (not the web app)
├── vendor/                    ← composer install target (gitignored)
├── .env / .env.example        ← script credentials (real .env is gitignored, lives at repo root)
│
├── web/                       ← the Laravel web app. See web/DEPLOYMENT.md
│
├── marketplaces/
│   ├── lib/                   ← shared PHP: bootstrap.php (autoload + .env + paths), API clients
│   ├── shopify/                scripts/ + data/{input,drafts,output}/
│   ├── ebay/                   scripts/ + data/{dows,ige}/{input,output}/
│   ├── amazon/                 scripts/ + data/{dows,ige}/{...}, lib/Amazon/Ai (title-generation)
│   └── walmart/                scripts/ + data/{us,ca}/{input,drafts,output}/
│
└── docs/                      ← cross-marketplace SEO strategy / research notes
```

---

## Setup

```bash
composer install                          # scripts (repo root)
cd web && composer install && cd ..        # web app has its own dependency tree
cp .env.example .env                       # fill in credentials, see the file's comments
```

Requires PHP 8.2+. Scripts and the web app both run under `php8.2` specifically in
this environment — use that binary if the bare `php` on your machine resolves to a
different version.

Run scripts directly from the repo root:

```bash
php marketplaces/shopify/scripts/<script>.php
php marketplaces/ebay/scripts/<script>.php --account=dows
```

Or run them from a browser via the web app — see `web/DEPLOYMENT.md`.

---

## Marketplace status

| Marketplace | Status |
|---|---|
| **Shopify** | Built. Core metadata pipeline (SEO description, product type, image alt) plus collections, GTIN/MPN/Google Shopping fields, an accessibility alt-text sweep, and a video/YouTube SEO pipeline. All write steps live. |
| **eBay** | Built. Item aspects and descriptions pipelines are live for both accounts (`dows`, `ige`); images pipeline is audit-only, no write step yet. |
| **Amazon** | Built. Audit → gap-fill → AI-draft → guarded write-back → projection into Usurper, with a write-safety layer (identifying-attribute guard, backups/restore, drift snapshot). See `marketplaces/amazon/README.md`. |
| **Walmart** | Scripts built, mirroring the eBay/Shopify shape (audit → author → reviewable output → guarded write). Not live yet — Walmart API credentials (`WALMART_CLIENT_ID_US`/`_CA`) aren't filled in. CA must migrate to Walmart's Global API by 2026-07-31. |

A step that applies across every marketplace: approved changes also need to be
imported into **Usurper** (the internal inventory system) so its own attributes stay
in sync with what's actually live. Amazon's pipeline does this today (Phase 9);
the others don't yet.

---

## The web app (`web/`)

Laravel app that wraps the scripts above for non-CLI use:

- Per-user accounts with self-service registration + email verification, and an
  admin role for user management.
- Live writes require an explicit confirmation step, and are blocked entirely for
  an eBay account with no backup on file (`/backups`).
- A connection-check widget on each script page pings the relevant marketplace
  account(s) before you run anything.
- Scheduled runs, but read-only scripts only — nothing that writes runs unattended.
- Run history with output/log downloads.

See `web/DEPLOYMENT.md` for the deploy runbook (two Composer trees, queue worker,
scheduler, first-admin bootstrap).

---

## Conventions

- **Credentials only in `.env`** (repo root, gitignored). Add new keys to
  `.env.example` with placeholder values so others know what's needed.
- **Each marketplace gets its own folder under `marketplaces/`** with `scripts/`,
  `data/`, and a README. Shared code goes in `marketplaces/lib/`.
- **Reads are safe; writes are guarded.** Audit/export scripts are read-only. Write
  scripts default to a dry run and require an explicit flag (`--apply`/`--live`) to
  do anything; eBay's write scripts also require re-typing the item id to confirm.
- **eBay: never rewrite a listing's variation-defining aspect value** (Size/Color/
  etc — check `varied_by` in `review_sheet.csv`). eBay ties sales history to the
  exact value; even a units-only reformat orphans it.
- **Shopify's `data/drafts/` is authored content** (descriptions/alts written by
  hand or by the AI-drafting step) — treat it as source of truth and commit it.
  `data/input/` and `data/output/` are regenerable.
- Lint changed PHP with `php8.2 -l` before committing.

See `marketplaces/<name>/README.md` for each marketplace's script inventory and run
order, and `docs/` for cross-marketplace strategy notes.
