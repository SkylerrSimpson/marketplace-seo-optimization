<?php

declare(strict_types=1);

/**
 * Compliance-critical attributes that must always be present with a verified
 * value before a listing is patched (stakeholder concern 4c: an open-ended
 * "always filled" list).
 *
 * AI is barred from authoring these values. Each attribute is either:
 *   - resolved deterministically by a rule (see lib/ComplianceResolvers.php), or
 *   - sourced from Usurper, or
 *   - left unfilled → patch_listings.php HARD-BLOCKS the entire SKU (W4d).
 *
 * Hand-editable, array-return (matches lib/UsurperAttributeMap.php). Add or
 * remove entries freely.
 */
return [
    'california_proposition_65',
    'pesticide_marking',
];
