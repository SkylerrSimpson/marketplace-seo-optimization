# eBay Description Re-Author — Task Spec (DRY-RUN, grounded)

You re-author product descriptions for an eBay catalog into a fixed two-paragraph
house style. You are given a batch of listings; for EACH one you write four fields.
**Every word must be grounded in that listing's provided source** (title,
short_description, narrative, feature_bullets, aspects). You may add wording, fix
grammar, and reorganize, but you may **NOT invent** specs, measurements, materials,
counts, certifications, brand claims, or compatibility that are not present in the
source. Do not lose real facts — every concrete detail in the source should survive
into `factual` or `bullets`.

## The four fields per listing

- **factual** — the FIRST paragraph. Concrete and factual: what the item IS, what's
  included, sizes/dimensions/quantity, material, brand, color, capacity. 2–4 sentences.
  Lead with the product. No hype.
- **sales** — the SECOND paragraph (sales pitch). Persuasive "why buy it": benefits,
  use cases, who it's for, the experience. 2–3 sentences. Must be **distinct** from
  `factual` (do not repeat the same sentences). Keep claims honest and grounded in the
  product's stated purpose.
- **bullets** — 3–6 Key Features as `"Label: detail"` strings. **Title Case the label**
  (e.g. `Stackable Design: Fits any 3 or 5 gallon bucket`). Derive from feature_bullets
  and aspects. Fix SCREAMING CAPS, fix spacing, keep concrete details. Drop boilerplate,
  store nav, promos, and identifiers.
- **mobile** — one human-readable summary string, **≤ 700 characters**, no HTML. Blend
  the key facts + the main benefit. This is the hidden eBay mobile description.

## Hard rules
- **NEVER** put an MPN / UPC / EAN / GTIN / SKU / ISBN / part number / model code in
  `factual`, `sales`, `bullets`, or `mobile`. They are machine codes; they live only in
  the (auto-generated) specs. If the source copy contains one, strip it out.
- Do **not** write the store name, brand footer, "Our Store", "Contact Us", shipping,
  returns, MSRP, or "Up to X% Off" — the template adds chrome separately.
- Plain text only (no HTML tags) in all four fields.
- Process **every** item in the input. Use the exact `item_id` given.

## Worked example (the house style)

Input (abridged): title "8pc ASR Outdoor Complete Gold Rush Sifting Classifier Sieve
Set", short_description about 8 stainless mesh sizes 1/2"–1/100" that fit 5-gal buckets,
bullets MULTIPLE MESH SIZES / STACKABLE DESIGN / RUGGED WEATHERPROOF / GOLD PROSPECTING /
MULTIPURPOSE.

Output:
```json
{"item_id":"236206634313","factual":"The ASR Outdoor 8pc Gold Classifier Set includes eight stackable stainless steel mesh sieves in graduated sizes from 1/2\" down to 1/100\". Each classifier is built from high-impact ABS with 304 stainless steel wire mesh, fits a standard 3 or 5 gallon bucket, and resists rust and corrosion under normal wear.","sales":"Spend less time sorting and more time finding color—stack the classifiers over a bucket and screen rocks and soil out of your pay dirt before running it through a pan or sluice. Versatile enough for prospecting, gardening, archaeology, construction, and metal detecting, this complete set helps you recover material faster.","bullets":["Multiple Mesh Sizes: 1/2\", 1/4\", 1/8\", 1/12\", 1/20\", 1/30\", 1/50\", 1/100\"","Stackable Design: Fits any standard 3 or 5 gallon bucket for efficient recovery","Rugged Weatherproof Build: High-impact ABS and 304 stainless steel wire mesh that won't rust","Gold Prospecting Equipment: Sift rocks and soil from pay dirt before panning or sluicing","Multipurpose Sifting Screens: Great for gardening, archaeology, construction, and metal detecting"],"mobile":"The ASR Outdoor 8pc Gold Classifier Set includes eight stackable stainless steel mesh sieves from 1/2\" to 1/100\", built from high-impact ABS and 304 stainless steel wire mesh. Each fits a standard 3 or 5 gallon bucket and resists rust, so you can quickly screen pay dirt before panning or sluicing."}
```

## Output
Append one JSON object per line (JSONL) to the output file you are given. One line per
listing. No surrounding array, no markdown fences in the file.
