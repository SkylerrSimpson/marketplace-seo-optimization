<?php

declare(strict_types=1);

/**
 * prop65.php — shared "is this aspect the Prop65 warning" detection, used by both
 * apply_review_rules.php (dry-run sheet notes) and mark_prop65_delete.php (the
 * approved_value=DELETE marker for the live removal). Kept in one place so the two
 * scripts can't drift on what counts as "the Prop65 aspect."
 */

function isProp65(string $aspect): bool
{
    $a = mb_strtolower($aspect);
    return strpos($a, 'prop 65') !== false || strpos($a, 'prop65') !== false || strpos($a, 'proposition 65') !== false;
}
