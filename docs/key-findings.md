# ASR Outdoor SEO/AI Project — Key Findings & Status (ref. 2026-06-03)

Consolidated reference. Deep research lives in `geo_seo_strategy.md`; phase plan/rules in
`phase1_rules.md`; this is the quick "what we know + what's left" sheet.

---

## The core insight: two different jobs

| Job | What does it | Status |
|---|---|---|
| **Win the click / supporting signal** | Meta descriptions (the search snippet) | In progress — drafting |
| **Get found & picked up** (Google + AI agents) | Feed / GTIN / reviews / structured data / body | Audited, not yet fixed |

**The feed is the backbone.** One Shopify→Google Merchant Center feed powers Google
Shopping, Google AI Mode/Gemini, and ChatGPT (~83% of ChatGPT product picks come from
Google Shopping data); Shopify Agentic Storefronts syndicates it to ChatGPT/AI Mode/
Perplexity/Copilot. Perplexity also reads the live open web.

### Honest impact hierarchy for getting picked up by AI agents
1. Complete, consistent **feed / structured data** (GTIN, price, availability) — biggest
2. **Reviews / ratings** (consensus signal AI trusts + mines for use-case answers)
3. **Product body** (specs, use-cases, FAQ)
4. **Off-site mentions** (listicles, Reddit, review platforms — ~85% of AI brand discovery)
5. **Meta description** — real but smallest

### What a meta description actually does (don't overstate it)
- NOT a Google ranking factor; Google **rewrites ~70%** of them.
- Powers the SERP **snippet → drives CTR (clicks)**. Strongest value is on Google.
- For AI agents: a **minor supporting text signal** (helped by our use-case/audience language).
- Bottom line: helps **win the click once you appear**; does not get you **found**.

---

## Meta-description work (Phase 1–2)
- Rules locked in `phase1_rules.md`: 140–160 chars, grounded in the real product body
  (boss requirement: condense, don't invent), benefit-led, includes use-case + audience,
  no hype/stuffing, brand ≤1×.
- Scope: meta description for ~199 products + `product_type` for 23 blanks
  (4 Fishing IDs → Fishing, rest → Survival/Camping).
- **Drafted in-session (no API key): 20/199 done, 179 to go.** Drafts in
  `drafts_manual.json` → `assemble_output.php` → reviewable `phase2_output.{csv,json}`.
- **Proven** on the 20: 20/20 in length band, 0 duplicates, 0 hype words, all share a key
  term with the title, brand ≤1×. ("ABS" caps are legit material names, kept.)
- Will re-run the same proof on all 199 before anything publishes. Nothing written to Shopify.

---

## Feed / structured-data audit (Phase A) — `feed_audit.csv`
Storefront: **asroutdoor.com** (Shopify, custom domain). 199 products.

| Gap | Count | Note |
|---|---|---|
| **GTIN/barcode missing** | 73 all + 11 partial (~42%) | **Biggest gap.** Needs real UPC values from supplier — cannot be invented |
| **No reviews/ratings** | catalog-wide | No reviews app installed → `aggregateRating` absent in schema |
| Images < 500px | 10 | Merchant Center enforces 500×500 Jan 2027 |
| Not available for sale | 10 | Won't surface in feeds |
| Not ACTIVE | 2 | — |
| No storefront URL | 5 | Invisible to open-web crawlers |
| Category / Brand | 0 missing | 100% present — strong |

**Storefront Product JSON-LD is already decent** (Shopify theme): name, brand, SKU, GTIN,
image, price, availability all populated on the sampled product (verified in raw HTML).
Gaps in schema are **reviews (`aggregateRating`)** and **GTIN coverage**.

---

## Action items (who can do what)
**I (Claude) can do without waiting:** export GTIN-gap worklist (84); check live robots.txt
+ draft `llms.txt` and crawler-allow rules; list the image/availability/URL gaps; write a
dry-run bulk-write script to load GTINs once values exist.

**Only ASR can do:** supply real GTIN/UPC values; install a reviews app (Judge.me/Yotpo) +
grow real reviews; provide hi-res images; enable Shopify Agentic Storefronts toggle; deploy
llms.txt/robots changes; fix product status/availability.

**Recommended extra phases (beyond original 6):** A feed/schema audit (done) → B enable
Agentic Storefronts → C on-page spec/use-case/FAQ → D reviews program → E off-site
consensus → F crawler access/llms.txt → G measurement.

---

## Project file map
- `audit_products.php` → `products_audit.csv` — Phase 0 gap audit (read-only)
- `export_descriptions.php` → `phase2_input.json` / `phase2_preview.csv` — real bodies (read-only)
- NOTE: files reorganized into shopify/scripts, shopify/data/{input,drafts,output}, docs/ — see README.md for the authoritative layout. (AI drafter removed; drafting was done in-session.)
- `drafts_manual.json` + `assemble_output.php` → `phase2_output.{csv,json}` — in-session drafts
- `audit_feed.php` → `feed_audit.csv` — Phase A structured-data audit (read-only)
- `phase1_rules.md` — field rules + AI prompt
- `geo_seo_strategy.md` — full GEO/AI research + roadmap
- `KEY_FINDINGS.md` — this file
