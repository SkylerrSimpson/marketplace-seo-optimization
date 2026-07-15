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
 *   'shape'     string  optional SP-API value shape the draft pass builds
 *                       (marketplace_id injected at runtime):
 *                         'value'        → [{value, marketplace_id}]  (bool/enum-string)
 *                         'localized'    → [{value, language_tag:en_US, marketplace_id}]
 *                         'gift_options' → [{can_be_messaged, can_be_wrapped, marketplace_id}]
 *                       omit for a plain scalar (e.g. product_tax_code) that
 *                       lib/AmazonPatch::formatPatchValue wraps on its own.
 *   'unit'      string  (unit_count only) the unit-count-type value, e.g. "count".
 *   'can_be_messaged' bool  (gift_options only) gift-message availability.
 *   'can_be_wrapped'  bool  (gift_options only) gift-wrap availability.
 *   'condition' string  optional gate: 'not_variation' skips variation members.
 *   'note'      string  optional provenance note stored on the draft entry.
 *
 * NOTE (2026-07-10): the boolean/enum defaults below encode stakeholder-mandated
 * fixed values verified against the SP-API schema enums. "California Air
 * Resources Board (CARB): Exempt" from the stakeholder list has NO attribute in
 * this marketplace's schema corpus (it exists only on composite-wood/furniture
 * product types Amazon does not expose here), so it is intentionally omitted.
 * Prop 65 and pesticide_marking are NOT here — they hard-block when unresolved,
 * so they live in lib/ComplianceAttributes.php + lib/ComplianceResolvers.php.
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

    // --- Stakeholder fixed regulatory/handling defaults (2026-07-10) ---------
    // Dangerous Goods Regulation: NO → "not_applicable" (enum).
    'supplier_declared_dg_hz_regulation' => ['value' => 'not_applicable', 'shape' => 'value'],
    // Product Subject to Buyer's Age Restrictions: NO.
    'is_this_product_subject_to_buyer_age_restrictions' => ['value' => false, 'shape' => 'value'],
    // Safety attestation (GPSR): YES.
    'gpsr_safety_attestation' => ['value' => true, 'shape' => 'value'],
    // Ships Globally: YES (some fringe use cases).
    'ships_globally' => ['value' => true, 'shape' => 'value'],
    // Has Replaceable Battery: NO.
    'has_replaceable_battery' => ['value' => false, 'shape' => 'value'],
    // Contains Liquid Contents: NO.
    'contains_liquid_contents' => ['value' => false, 'shape' => 'value'],
    // Warranty Description (localized string; language_tag is schema-required).
    'warranty_description' => ['value' => 'Limited Manufacturer Direct', 'shape' => 'localized'],
    // Gift Message: NO, Gift Wrap: NO.
    'gift_options' => ['shape' => 'gift_options', 'can_be_messaged' => false, 'can_be_wrapped' => false],
];
