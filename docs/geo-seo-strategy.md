# ASR Outdoor — SEO + AI/Agent Visibility Strategy (research, 2026-06)

Goal: maximize the chance that **both** classic search (Google) **and** AI agents
(ChatGPT, Gemini/Google AI Mode, Perplexity, Microsoft Copilot, Amazon Rufus/"Alexa
for Shopping") surface ASR Outdoor products when a user asks a natural-language
question like *"best beginner gold panning kit for the PNW."*

---

## The single most important finding

**The product feed/catalog is the shared backbone for almost all of it.** The same
structured product data — pushed from Shopify → Google Merchant Center (and Shopify's
own Catalog) — feeds:

- Google Shopping + Google **AI Mode / Gemini** (AI Mode reads the Shopping Graph,
  which is populated from Merchant Center feeds + on-page Product schema).
- **ChatGPT** shopping — research found **~83% of ChatGPT's product-carousel picks are
  sourced from Google Shopping data**. ChatGPT does not crawl to build its catalog; it
  fans out intent queries against that index.
- **Shopify Agentic Storefronts**, which syndicate the Shopify catalog directly to
  ChatGPT, Google AI Mode, Perplexity, and Copilot.

So enriching the Shopify product record (our current project) is the highest-leverage
work — it pays off across Google **and** the AI agents at once. Perplexity is the main
exception: it reads the **live open web** at query time (no feed), so it depends on
crawlable product pages + off-site mentions.

---

## Two distinct AI-visibility channels (optimize both)

| Channel | Who it drives | Primary lever |
|---|---|---|
| **Feed / catalog** | ChatGPT carousels, Gemini/AI Mode, Google Shopping, Perplexity Shopping, Rufus (if on Amazon) | Complete, consistent **structured product data** in Merchant Center / Shopify Catalog |
| **Open web / citation** | Perplexity, ChatGPT browsing, Google AI Overviews | Crawlable product pages + **Product JSON-LD** + **off-site consensus** (reviews, listicles) |

---

## What the AI engines actually reward (consolidated from 2026 sources)

1. **Structured product data completeness & validity.** JSON-LD `Product` + `Offer`:
   `name`, `brand`, `gtin`/`mpn`/`sku`, `image`, `price`, `priceCurrency`,
   `availability`, `condition`, `aggregateRating`, `description`, `MerchantReturnPolicy`.
   Required for Merchant listings: name, image, offers(price+currency). The rest is
   "recommended" but is exactly what AI uses to match and compare.

2. **Consistency across surfaces.** Price, name, and availability must match across the
   product page, the JSON-LD, the Merchant feed, and any third-party listing. Mismatches
   create "trust deficits" that suppress recommendations.

3. **Specifications as structured facts.** Spec tables / bullet lists with quantifiable
   attributes (dimensions, weight, capacity, material, load rating, grit, lumens) — not
   buried in prose. AI matches these to specific requirements.

4. **Use-case + target-audience language ("intent matching").** AI matches *conversational,
   situational* queries, not category keywords. "Quiet vacuum for cat hair on hardwood"
   → the page must contain that situational language. For us: beginner vs. expert, the
   activity/context (river/creek prospecting, camping, emergency kit), who it's for.
   This is the lever for the *"beginner … PNW"* example — name the user and the use case.

5. **FAQ / Q&A blocks.** Question→answer pairs sourced from real shopper questions,
   support tickets, reviews, Reddit. Long-tail, use-case-specific. Strong AI signal.

6. **Reviews & ratings (consensus).** Display rating + count; benchmark ~150+ reviews;
   review *text* that mentions use cases ("used this for cold-weather camping") is what
   Rufus/ChatGPT mine to answer intent questions. AI aggregates ratings across retailers.

7. **Off-site consensus / third-party mentions.** ~85–90% of AI brand discovery traces
   to third-party sources — **listicles, roundups, comparison articles, genuine Reddit
   threads, review platforms.** Independent validation outweighs brand copy. GPT-5 leans
   heaviest on third-party; Claude/Perplexity reference brand-owned content a bit more.

8. **Verifiable, specific claims; no unverifiable superlatives.** Replace "best in class"
   with measured facts ("removes 99.97% of 0.3-micron particles"). Confident, factual,
   recent content is favored for citation.

9. **AI crawler access.** Allow `GPTBot`, `ClaudeBot`, `PerplexityBot`, `Google-Extended`
   in robots.txt; consider an **llms.txt** at the domain root pointing to the catalog/key
   pages. If you block the crawlers, the open-web channel goes dark.

10. **Image quality + freshness.** Merchant Center min image is moving to **500×500**
    (warnings Apr 2026, enforced Jan 2027). Keep price/inventory fresh; stale data loses
    citations to updated competitors.

---

## How this reshapes our project

Our meta-description + product_type work is **necessary but not sufficient**. The 160-char
meta description mainly serves the Google SERP/Merchant-listing snippet path. For AI agent
pickup, the **product body, structured attributes, and off-site signals matter more.**
Reframe the unit of optimization from "meta description" to "the whole product record."

**Refinements to fold into the current phases:**
- **Meta descriptions:** explicitly include the **primary use case + target audience**
  (beginner/expert, the activity) so they map to conversational queries — still grounded
  in the real body (boss requirement intact). Done in `phase1_rules.md`.
- **Product bodies:** ensure each has a clean **spec block** + **use-case/audience**
  language + ideally a short **FAQ**. Many of our bodies already have spec lists (good).
- **Structured attributes (feed):** audit GTIN/MPN, brand, condition, availability,
  Google product category, plus apparel-style attributes where relevant. This is the
  biggest AI lever and is currently unaudited.

## Recommended roadmap additions (beyond the original 6 phases)

- **Phase A — Feed/structured-data audit & enrichment.** Verify Shopify → Merchant Center
  feed exists and is complete (GTIN/brand/condition/availability/category/image ≥500px),
  and that Product JSON-LD on the storefront is complete and matches the feed. *(Highest
  ROI for AI agents — promote near the front.)*
- **Phase B — Enable Shopify Agentic Storefronts** (toggle ChatGPT / Google AI Mode /
  Perplexity / Copilot channels) so the catalog is syndicated to the agents out of the box.
- **Phase C — On-page enrichment:** spec tables + use-case/audience copy + FAQ blocks per
  product (feeds both Google and AI matching).
- **Phase D — Reviews program:** systematically grow reviews (Judge.me/Yotpo/Shopper
  Approved), prompting for *use-case context* in the review text; surface aggregateRating
  in schema.
- **Phase E — Off-site consensus:** pursue category listicles/roundups, comparison
  articles, review-platform presence, authentic community mentions. ~85% of AI discovery.
- **Phase F — Crawler access:** allow GPTBot/ClaudeBot/PerplexityBot/Google-Extended;
  publish llms.txt.
- **Phase G — Measure:** manual query testing in each AI platform, GA4 referrals from
  chatgpt.com / perplexity.ai / gemini, Merchant Center diagnostics, AI-visibility tools.

## Sources (2026)
- Salsify, BigCommerce, Search Engine Land (GEO guides; AI-ready product-page scorecard)
- ALM Corp (product-page AI optimization; Merchant Center 2026 spec update)
- Amalytix / Seller Labs / Tinuiti (Amazon Rufus → "Alexa for Shopping")
- Marpipe / Passionfruit / Athos Commerce (product feeds for AI; "83% from Google Shopping")
- OpenAI "Buy it in ChatGPT" / Ask Phill / Modern Retail (Agentic Commerce Protocol, Shopify Agentic Storefronts)
- AirOps / Alhena (off-site signals: ~85% of brand discovery from third-party sources)
- Google Search Central (Merchant listing & Product structured data docs)
