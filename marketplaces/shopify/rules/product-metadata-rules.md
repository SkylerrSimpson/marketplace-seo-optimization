# Phase 1 — Field Rules & AI Drafting Prompt

**Status:** draft for review (no Shopify writes, no content generated yet)
**Scope locked (2026-06-03):** (1) SEO meta description for ~199 products, (2) `product_type` for the 23 blanks. Image alt text and title rewrites are OUT of scope for this pass.

---

## 1. Meta description rules

**Grounding (source of truth):** each meta description is a faithful **condensation of the product's existing body description** (`descriptionHtml`), not a fresh invention. The body is the baseline; the AI summarizes and sharpens it for search, pulling real attributes (material, size, count, use) straight from it. Facts not in the body (or title) may not be introduced. This keeps every description accurate to what the product actually is. Where the body is thin (the 11 flagged `thin_body` products), the AI leans on title + category and must stay conservative — no filling gaps with guesses.

**Length:** 140–160 characters. Hard ceiling 160 (matches `SEO_MAX` in the audit). Never below 70 (`SEO_MIN`). Phase 2 enforces this programmatically and re-drafts anything out of range.

**Structure (in order):**
1. Lead with the concrete benefit or primary attribute — what it does / why it matters.
2. Work in the key search term naturally (usually the product noun: "survival multitool", "emergency blanket"). No stuffing, no repetition.
3. **Include the use case + target audience when the body supports it** (e.g. "for beginners", "for camping and emergency kits", "river and creek gold prospecting"). This is what makes the description match conversational AI-agent queries like *"best beginner gold panning kit"* — see `geo_seo_strategy.md` §4. Stay grounded: only state an audience/use named or clearly implied by the body; do NOT invent a geography (no "PNW" unless the body says so).
4. Close with a differentiator (material, size, count, packability) when room allows.

**Voice:** plain, confident, outdoors/utility tone. Active voice. No hype words ("amazing", "best ever"), no ALL CAPS, no emoji. American spelling.

**Hard don'ts:**
- No keyword stuffing (the anti-pattern: *"20oz rock pick hammer 20oz geology mining tool rock hounding fossil digging gold"*).
- No duplicate descriptions across products — each must be specific to that item.
- No claims not supported by the title/body (no invented certifications, sizes, or materials).
- Don't restate the brand name redundantly; "ASR Outdoor" at most once, only if it reads naturally.

**Regenerate, don't keep:** all 5 existing meta descriptions are low quality (2 too short, 1 stuffed) — Phase 2 redrafts all ~199. There are no "good" ones to protect.

---

## 2. Title convention

**Decision: leave titles as-is this pass.** Titles are already attribute-rich (e.g. *"Survival 7 in 1 Multitool with Whistle Compass LED Flashlight"*) and rewriting 199 titles is risk + scope we don't need yet. Revisit only if Merchant Center flags title quality.

Convention to apply *if* we ever do titles: `ASR Outdoor + [key attributes: material / size / color / count]`.

---

## 3. product_type mapping (the 23 blanks)

Vendor is 100% "ASR Outdoor". Categories (Shopify taxonomy) are already 100% assigned, so we are NOT remapping categories — only filling the empty `product_type` text field. Buckets (5 existing + 1 new):

`Survival/Camping` · `Gold Panning` · `Rock Hounding` · `Metal Detecting` · **`Fishing` (new)**

**Rule:** assign each blank to the bucket matching its taxonomy category.

**Decision (2026-06-03): create a new `Fishing` product_type.** Split of the 23 blanks:
- **`Fishing` (4):** Fishing Fillet Knife (#1), Grooved Angle Sharpening Stone for Fish Hooks (#7), 400lb Magnet Fishing Kit (#19), Nylon Fishing Vest (#20).
- **`Survival/Camping` (19):** all remaining blanks.

> Note: the Magnet Fishing Kit (#19) is magnet retrieval, not angling — borderline `Fishing` vs. `Survival/Camping`. Assigned to `Fishing` per its name/category; flag if you'd rather it sit in Survival/Camping.

---

## 4. AI drafting prompt (used in Phase 2, run per product)

> You are writing the SEO meta description for an ASR Outdoor product (outdoor, survival, prospecting, and camping gear).
>
> INPUT (per product): title, the full existing product body description, product category, key attributes.
>
> Use the existing product body description as your factual baseline. Your job is to condense and sharpen it for search — summarize what is already there. Do NOT introduce any fact (material, size, certification, use) that is not present in the body or title. If the body is short or sparse, stay conservative and describe only what is stated.
>
> Write ONE meta description that:
> - is 140–160 characters (count carefully; never exceed 160 or fall below 70);
> - leads with the concrete benefit or primary attribute of the product (drawn from the body);
> - includes the main search term (the product noun) naturally, used once;
> - ends with a real differentiator (material, size, count, packability) if space allows;
> - uses plain, confident, active-voice American English — no hype words, no ALL CAPS, no emoji, no keyword stuffing, no repeated words;
> - states only facts supported by the provided title/body — invent nothing;
> - mentions "ASR Outdoor" at most once and only if natural.
>
> Output only the meta description text, nothing else.

---

## Phase 2 data prerequisite (new — from the "use real descriptions" requirement)

The meta descriptions must be grounded in each product's real body text, but `products_audit.csv` stores only `body_len`, not the body itself. So Phase 2 starts with a small **read-only export step**: re-pull `descriptionHtml` (plus id, title, productType, category) for all 199 products into a Phase 2 input file (JSON or CSV). The generator reads from that. No new Shopify write — just a richer read than the audit kept.

---

## Sample drafts (pressure-test — illustrative; drafted from titles only, will be redone from real bodies in Phase 2)

- **Survival 7 in 1 Multitool with Whistle Compass LED Flashlight**
  > "Stay ready anywhere with this 7-in-1 survival multitool — a built-in whistle, compass, and LED flashlight packed into one compact, rugged outdoor tool."
- **Survival Mylar Extreme Emergency Blanket, Orange**
  > "Lock in body heat fast with this Mylar emergency blanket. Compact, lightweight, and high-visibility orange — essential gear for any survival or first-aid kit."
- **Fishing Fillet Knife Hard Sheath and Floating Handle**
  > "Clean every catch with this fishing fillet knife. The floating handle won't sink if dropped, and the hard sheath keeps the blade safe between trips."

(Length tuned by Phase 2; these show tone, structure, and the no-stuffing rule.)
