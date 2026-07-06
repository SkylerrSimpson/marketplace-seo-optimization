<?php

declare(strict_types=1);

/**
 * Single source of truth for "is this attribute identifying?"
 *
 * Identifying attributes are the ones that define which physical product /
 * variation an ASIN is — the ones a bad write can use to silently detach a
 * listing from its ASIN, merge two products, or split a variation family.
 * Amazon treats these differently from descriptive copy: a wrong bullet_point
 * is cosmetic; a wrong variation_theme or product identifier can lose the
 * listing. patch_listings.php therefore holds these back unless the operator
 * explicitly opts in with --include-identifying.
 *
 * The identifying set is HYBRID:
 *   1. CURATED — a hand-maintained list below (edit freely). These are always
 *      identifying regardless of product type.
 *   2. schemaVariationAttributes(productType) — the variation-defining
 *      attributes Amazon declares for THIS product type, parsed from the
 *      cached schema (data/schemas/{PRODUCT_TYPE}.json). e.g. an ACCESSORY
 *      whose variation_theme enum is COLOR_NAME/SIZE_NAME contributes
 *      color_name and size_name.
 *
 * Pure / read-only. No API calls. Worst case (missing/unparseable schema) the
 * curated list is the backstop.
 */
final class IdentifyingAttributes
{
    /**
     * Hand-maintained. Always identifying, product-type independent.
     * Edit freely — a non-developer can add/remove entries here.
     */
    public const CURATED = [
        // Variation / grouping
        'variation_theme',
        'parentage_level',
        'child_parent_sku_relationship',
        // Quantity semantics (stakeholder 4d: manual inclusion in the guard)
        'item_quantity',
        'number_of_items',
        'item_package_quantity',
        // Product identifiers
        'externally_assigned_product_identifier',
        'standard_product_id',
        'other_product_id',
        'merchant_suggested_asin',
        'gtin',
        'upc',
        'ean',
        // Type identity
        'product_type',
        'item_type_keyword',
        'item_type_name',
    ];

    /** Memoization: PRODUCT_TYPE => [token, ...]. Schemas are large. */
    private static array $variationCache = [];

    /**
     * Variation-defining attributes for a product type, parsed from its cached
     * schema. Returns lowercase token names (e.g. ['color_name','size_name']).
     * Missing schema or missing variation_theme → [].
     */
    public static function schemaVariationAttributes(string $productType, string $schemasDir): array
    {
        $productType = strtoupper(trim($productType));
        if ($productType === '') {
            return [];
        }
        if (isset(self::$variationCache[$productType])) {
            return self::$variationCache[$productType];
        }

        $file = rtrim($schemasDir, '/') . '/' . $productType . '.json';
        if (!is_file($file)) {
            return self::$variationCache[$productType] = [];
        }

        $schema = json_decode((string) file_get_contents($file), true);
        if (!is_array($schema)) {
            return self::$variationCache[$productType] = [];
        }

        $nameNode = $schema['properties']['variation_theme']['items']['properties']['name'] ?? null;
        if (!is_array($nameNode)) {
            return self::$variationCache[$productType] = [];
        }

        // Union active enum + deprecated themes (a listing may still use one).
        $themes = array_merge(
            $nameNode['enum'] ?? [],
            $nameNode['$lifecycle']['enumDeprecated'] ?? [],
        );

        $tokens = [];
        foreach ($themes as $theme) {
            // e.g. "COLOR_NAME/SIZE_NAME" → color_name, size_name
            foreach (explode('/', (string) $theme) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $tokens[$part] = true;
                }
            }
        }

        return self::$variationCache[$productType] = array_keys($tokens);
    }

    /**
     * CURATED ∪ schemaVariationAttributes(productType).
     * $productType may be null (curated-only check).
     */
    public static function isIdentifying(string $attr, ?string $productType, string $schemasDir): bool
    {
        $attr = strtolower(trim($attr));
        if (in_array($attr, self::CURATED, true)) {
            return true;
        }
        if ($productType !== null && $productType !== '') {
            return in_array($attr, self::schemaVariationAttributes($productType, $schemasDir), true);
        }
        return false;
    }

    /**
     * Split attribute names into ['identifying' => [...], 'safe' => [...]],
     * preserving input order within each bucket.
     */
    public static function classify(array $attrNames, ?string $productType, string $schemasDir): array
    {
        $identifying = [];
        $safe        = [];
        foreach ($attrNames as $attr) {
            if (self::isIdentifying((string) $attr, $productType, $schemasDir)) {
                $identifying[] = $attr;
            } else {
                $safe[] = $attr;
            }
        }
        return ['identifying' => $identifying, 'safe' => $safe];
    }
}
