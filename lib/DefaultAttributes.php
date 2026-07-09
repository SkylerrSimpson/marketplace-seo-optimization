<?php

declare(strict_types=1);

/**
 * Stakeholder-mandated default attributes applied during drafting.
 *
 * These are attributes the stakeholder wants EVERY draft to account for, filled
 * fill-missing only (an existing draft/listing value is never overwritten) and
 * only where the product-type schema actually defines the attribute. Two kinds:
 *
 *   - NULL defaults (document-only): recorded in the draft with value=null so
 *     the intent is visible/reviewable, but patch_listings.php skips null values
 *     — so nothing is written to Amazon for these. They exist to make "we
 *     deliberately leave this empty" explicit rather than accidental.
 *
 *   - VALUE defaults (real values): patched through the normal candidate path.
 *       product_tax_code → A_GEN_TAX
 *       unit_count       → { value: 1, unit: "count" }, but ONLY when the SKU is
 *                          not a variation member (a variation's unit count is
 *                          part of its identity; leave it to the parent/child).
 *
 * Compliance-critical attributes that must HARD-BLOCK when unresolved live in
 * lib/ComplianceAttributes.php instead — these defaults never block.
 *
 * Hand-editable, array-return (matches lib/ComplianceAttributes.php /
 * lib/UsurperAttributeMap.php). Each entry:
 *   'value'     mixed   the default (null for document-only defaults).
 *   'unit'      string  (unit_count only) the unit-count-type value, e.g. "count".
 *   'condition' string  optional gate: 'not_variation' skips variation members.
 *   'note'      string  optional provenance note stored on the draft entry.
 */
return [
    // --- NULL defaults (document-only; never patched) ------------------------
    'is_green_purchasing_law_compliant'        => ['value' => null],
    'government_contract_information'           => ['value' => null],
    'fcc_radio_frequency_emission_compliance'  => ['value' => null],
    'regulatory_compliance_certification'      => ['value' => null],
    'compliance_media'                         => ['value' => null],
    'non_lithium_battery_packaging'            => ['value' => null],
    'non_lithium_battery_energy_content'       => ['value' => null],
    'has_less_than_30_percent_state_of_charge' => ['value' => null],
    'fulfillment_availability'                 => ['value' => null, 'note' => 'handled at the account level'],
    'supplemental_condition_information'       => ['value' => null],

    // --- VALUE defaults (real values; fill-missing, patched normally) --------
    'product_tax_code' => ['value' => 'A_GEN_TAX'],
    'unit_count'       => ['value' => 1, 'unit' => 'count', 'condition' => 'not_variation'],
];
