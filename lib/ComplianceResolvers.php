<?php

declare(strict_types=1);

/**
 * Deterministic resolvers for compliance-critical attributes.
 *
 * These produce compliance values by RULE, not by AI authoring. If an
 * attribute in lib/ComplianceAttributes.php has no resolver here, it must be
 * sourced from Usurper or it hard-blocks the SKU at patch time.
 *
 * Currently resolved:
 *   california_proposition_65 — chemical warning, keyed on product type.
 *
 * Legal note: LEAD_PRODUCT_TYPES is a maintained list. Keep it current for
 * genuinely lead-bearing metal goods. Everything not on the list defaults to
 * bisphenol_a_bpa (the plastics/packaging default the stakeholder chose).
 */
final class ComplianceResolvers
{
    /**
     * Product types whose Prop 65 chemical is "lead" — edged blades and metal
     * hand tools. Seeded from a catalog-type scan of the two seller accounts
     * plus the stakeholder's KNIFE/MULTITOOL/SWORD/SAW guidance; extend freely.
     * Types NOT listed default to bisphenol_a_bpa.
     */
    public const LEAD_PRODUCT_TYPES = [
        'KNIFE', 'KITCHEN_KNIFE', 'UTILITY_KNIFE', 'KNIFE_BLOCK_SET', 'KNIFE_SHARPENER',
        'PALETTE_PUTTY_KNIFE', 'BLADED_FOOD_PEELER', 'SWORD', 'AXE', 'SAW', 'SAW_BLADE',
        'MULTITOOL', 'TOOLS', 'KITCHEN_TOOLS', 'GARDEN_TOOL_SET', 'ROTARY_TOOL', 'CHISEL',
        'HAMMER_MALLET', 'SCREWDRIVER', 'WRENCH', 'PLIERS', 'CRIMPING_PLIERS', 'SHOVEL_SPADE',
        'DRILL_BITS', 'SCISSORS', 'GARDEN_SHEAR_SCISSORS', 'WHEEL_CUTTER', 'NAIL_FILE', 'WIRE',
        'SEWING_TOOL_SET', 'BARBECUE_TOOL_SET', 'COOKIE_CUTTER',
    ];

    /**
     * california_proposition_65 value for a product type. Deterministic.
     * Returns the SP-API attribute value shape (array of one slot object).
     */
    public static function prop65(string $productType, string $marketplaceId): array
    {
        $chemical = in_array(strtoupper(trim($productType)), self::LEAD_PRODUCT_TYPES, true)
            ? 'lead'
            : 'bisphenol_a_bpa';

        return [[
            'compliance_type' => 'chemical',
            'chemical_names'  => [$chemical],
            'marketplace_id'  => $marketplaceId,
        ]];
    }

    /** Does a deterministic resolver exist for this compliance attribute? */
    public static function has(string $attr): bool
    {
        return $attr === 'california_proposition_65';
    }

    /**
     * Resolve a compliance attribute to its SP-API value, or null if no
     * resolver exists (caller must then source it from Usurper or block).
     */
    public static function resolve(string $attr, string $productType, string $marketplaceId): ?array
    {
        return match ($attr) {
            'california_proposition_65' => self::prop65($productType, $marketplaceId),
            default                     => null,
        };
    }
}
