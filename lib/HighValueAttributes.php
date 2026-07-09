<?php

declare(strict_types=1);

/**
 * Single source of truth for "is this attribute worth authoring / worth Opus?"
 *
 * Amazon product-type schemas carry ~130-150 properties of which only ~7 are
 * required. Authoring the entire optional tail with the most expensive model is
 * what made Phase 7 expensive (and, for context-less SKUs, produced hallucinated
 * data). draft_listings.php therefore authors only:
 *
 *   required attributes  ∪  this curated high-value allowlist
 *
 * unless the operator opts back in to the full tail with --include-recommended
 * (or the --full umbrella). The allowlist is the set of descriptive / SEO fields
 * that actually move a listing: title, bullets, description, keywords, and the
 * handful of variation-descriptive attributes buyers filter on.
 *
 * MARQUEE is the narrower subset worth Opus-grade prose (the customer-facing
 * title/description content). Everything else non-enum is authored by Sonnet,
 * and enum/short-string fields by Haiku — see draft_listings.php pickModel().
 *
 * Hybrid, mirroring lib/IdentifyingAttributes.php:
 *   1. CURATED — hand-maintained, product-type independent (edit freely).
 *   2. PER_PRODUCT_TYPE — optional per-schema additions.
 *
 * Pure / read-only. No API calls.
 */
final class HighValueAttributes
{
    /**
     * Hand-maintained high-value allowlist. Always authored (with required).
     * Edit freely — a non-developer can add/remove entries here.
     */
    public const CURATED = [
        'item_name', // <= 75 characters used in conjunction with Amazon modular titles (effective 2026-07-27)
        'title_differentiation', // Amazon modular titles (effective 2026-07-27)
        'bullet_point',
        'product_description',
        'generic_keyword',
        'brand',
        'color',
        'material',
        'size',
        'item_type_keyword',
    ];

    /**
     * Optional per-product-type additions, merged with CURATED. Keys are
     * UPPERCASE product types; values are attribute-name lists.
     * e.g. 'FLASHLIGHT' => ['light_source_type', 'battery_cell_composition'].
     */
    public const PER_PRODUCT_TYPE = [];

    /**
     * Narrow set worth Opus-grade prose — the customer-facing title/description
     * content. Everything else routes to Sonnet (prose) or Haiku (enum/short).
     */
    public const MARQUEE = [
        'item_name',
        'title_differentiation',
        'bullet_point',
        'product_description',
    ];

    /** CURATED ∪ PER_PRODUCT_TYPE[productType]. */
    public static function isHighValue(string $attr, ?string $productType = null): bool
    {
        $attr = strtolower(trim($attr));
        if (in_array($attr, self::CURATED, true)) {
            return true;
        }
        if ($productType !== null && $productType !== '') {
            $extra = self::PER_PRODUCT_TYPE[strtoupper(trim($productType))] ?? [];
            return in_array($attr, array_map('strtolower', $extra), true);
        }
        return false;
    }

    /** Worth Opus prose. */
    public static function isMarquee(string $attr): bool
    {
        return in_array(strtolower(trim($attr)), self::MARQUEE, true);
    }
}
