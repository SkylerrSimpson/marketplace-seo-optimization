# Architecture

DOWScripts is a **catalog/SEO optimization platform** for the product listings that
[Irongate Enterprises (IGE)](#) and [Deals Only Web Store (DOWS)](#) sell across
Amazon, eBay, Walmart, and Shopify. It has two halves that ship as **one repository
and deploy as one unit**:

```
dowscripts/                 ← this repo
├── web/                    the Laravel web app — the UI/orchestration layer users log into
├── marketplaces/           the per-marketplace CLI tooling that does the real work
│   ├── ebay/               scripts/ + data/  (per-account: dows, ige)
│   ├── shopify/            scripts/ + data/
│   ├── amazon/             scripts/ + data/  (per-account)
│   ├── walmart/            scripts/ + data/  (per-country: us, ca)
│   └── lib/                shared PHP for the scripts (clients, bootstrap, attribute maps)
├── docs/                   cross-cutting design notes
├── vendor/                 Composer deps for the scripts (eBay SDK, phpdotenv, …)
├── composer.json           → package "dowscripts/scripts"
└── ARCHITECTURE.md         (this file)
```

Vocabulary, so it's unambiguous:

- **DOWScripts** = the platform (this repo).
- **`web/`** = the DOWScripts web app (Laravel: auth, admin, queue, scheduler).
- **`marketplaces/<name>/`** = the CLI tools the web app drives, plus their data.

## The core rule: the web app never reimplements marketplace logic

`web/` is a thin skin. It **shells out** to the scripts in `marketplaces/` as
subprocesses — including their `--live` flag for real writes — and never talks to a
marketplace API directly. All the hard-won correctness (eBay's vary-by guard, the
MULTI-cardinality comma-split fix, Shopify's full-replace lesson) lives in the
scripts and stays there. See `web/CLAUDE.md` for why this is load-bearing.

This is why you can't split them apart casually: delete `marketplaces/` and every
button in `web/` has nothing to run.

## How `web/` locates the scripts (the coupling, in one place)

The web app assumes it sits one level below the repo root, with `marketplaces/` as a
sibling. It resolves everything from a single computed value:

```php
config('paths.repo_root')   // = dirname(base_path())  → the repo root
```

- **Running a script:** the queue job (`RunScriptJob`) sets the working directory to
  the repo root and invokes e.g. `php8.2 marketplaces/ebay/scripts/export_listings.php`.
  Script paths in `web/config/scripts/*.php` are repo-root-relative.
- **Reading a script's output/backups:** same repo-root-relative resolution
  (`marketplaces/ebay/data/{account}/…`).

The scripts, in turn, locate **their own** data and shared code relative to their
file (`__DIR__`), and centralize all path constants in
`marketplaces/lib/bootstrap.php` — so the two halves can be moved or renamed as a
unit without editing dozens of files (which is exactly how this layout was reached).

## Deployment

Deploy the **whole tree together** — they're version-locked by design. Two Composer
installs (root + `web/`), a web server pointed at `web/public`, and two always-on
background processes (a queue worker and the scheduler). Full runbook, production
`.env`, and first-admin bootstrap: **`web/DEPLOYMENT.md`**.
