<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Everything the title / highlight prompts need about one product, resolved
 * once. Replaces the loose positional parameter lists the old procedural
 * buildItemNamePrompt() carried.
 *
 * Values are already flattened out of the SP-API slot shape
 * (attributes[X][0].value); use fromSnapshots() to read them off disk, or
 * construct directly from already-resolved locals (draft_listings.php).
 *
 * itemNameMaxLen defaults to 75 — the Amazon modular-title business rule, not
 * the item_name schema maxLength (200). titleDiffMaxLen is schema-driven (125)
 * with a 125 fallback when the schema omits maxLength.
 */
final class ProductContext
{
    /**
     * @param list<string> $features bullet_point values (product features)
     */
    public function __construct(
        public readonly string $sku,
        public readonly string $productType,
        public readonly string $brand,
        public readonly string $existingTitle,
        public readonly string $description,
        public readonly array  $features = [],
        public readonly string $searchTerms = '',
        public readonly string $itemNameSchemaDescription = '',
        public readonly string $titleDiffSchemaDescription = '',
        public readonly int $itemNameMaxLen = 75,
        public readonly int $titleDiffMaxLen = 125,
    ) {
    }

    /**
     * True when there is enough context to attempt generation at all — matches
     * the $canSuggestTitle gate in draft_listings.php.
     */
    public function hasContext(): bool
    {
        return $this->existingTitle !== ''
            || $this->description !== ''
            || array_filter($this->features) !== [];
    }

    /**
     * Product type used to key the schema: the summaries node (what
     * analyze_gap_fill.php and the draft pipeline use), falling back to
     * productTypes.
     *
     * @param array<string,mixed> $listing
     */
    public static function resolveProductType(array $listing): string
    {
        return (string) (
            $listing['summaries'][0]['productType']
            ?? $listing['productTypes'][0]['productType']
            ?? ''
        );
    }

    /**
     * Build a ProductContext from raw on-disk snapshots for generate_titles.php.
     *
     * Product type prefers the summaries node (the key analyze_gap_fill.php and
     * the draft pipeline use for the schema), falling back to productTypes.
     * Brand prefers this ASIN's own catalog snapshot over the listing.
     *
     * @param array<string,mixed> $listing      getListingsItem snapshot
     * @param array<string,mixed> $catalogAttrs catalog attributes node (input/catalog/{asin}.json -> attributes)
     * @param array<string,mixed> $schema       product-type JSON schema
     */
    public static function fromSnapshots(array $listing, array $catalogAttrs, array $schema): self
    {
        $listingAttrs = $listing['attributes'] ?? [];
        $productType  = self::resolveProductType($listing);

        $brand = self::slotValue($catalogAttrs, 'brand')
            ?: self::slotValue($listingAttrs, 'brand');

        $existingTitle = self::slotValue($listingAttrs, 'item_name')
            ?: (string) ($listing['summaries'][0]['itemName'] ?? '');

        $features = [];
        foreach ($listingAttrs['bullet_point'] ?? [] as $slot) {
            $v = trim((string) ($slot['value'] ?? ''));
            if ($v !== '') {
                $features[] = $v;
            }
        }

        $props = $schema['properties'] ?? [];

        return new self(
            sku:                        (string) ($listing['sku'] ?? ''),
            productType:                $productType,
            brand:                      $brand,
            existingTitle:              $existingTitle,
            description:                self::slotValue($listingAttrs, 'product_description'),
            features:                   $features,
            searchTerms:                self::slotValue($listingAttrs, 'generic_keyword'),
            itemNameSchemaDescription:  SchemaReader::description($props['item_name'] ?? []),
            titleDiffSchemaDescription: SchemaReader::description($props['title_differentiation'] ?? []),
            titleDiffMaxLen:            SchemaReader::maxLength($props['title_differentiation'] ?? []) ?? 125,
        );
    }

    /**
     * First slot value for an attribute in the SP-API [{value, ...}] shape,
     * flattening array values (e.g. standardized_values) like listingAttr().
     *
     * @param array<string,mixed> $attributes
     */
    private static function slotValue(array $attributes, string $attr): string
    {
        $v = $attributes[$attr][0]['value'] ?? '';
        return is_array($v) ? implode(', ', $v) : trim((string) $v);
    }
}
