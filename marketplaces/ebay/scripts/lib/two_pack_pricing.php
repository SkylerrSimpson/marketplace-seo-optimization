<?php

declare(strict_types=1);

/**
 * two_pack_pricing.php — the shared title/price/quantity rules, used by
 * find_two_pack_candidates.php (which needs them to compute a real PREVIEW of
 * what a listing would become for the reviewable candidates file) and
 * create_two_pack_listings.php (which needs them to build the actual listing).
 * Extracted so those two can never drift apart on the math. See
 * ebay/docs/two_pack_rules.txt for the human-readable version of these rules.
 */

/**
 * Finds the nearest cent value to $rawDollars whose final digit is one of
 * $targetLastDigits (scans a full decade both directions so it always finds
 * one). "Nearest 10 cents" / "nearest 9 cents" in the pricing rule below both
 * mean charm-pricing endings (last digit 0 or 9), NOT a step of $0.09/$0.10 —
 * e.g. a source ending in .99 should land on something like $15.99, not
 * $16.02 (round($raw/0.09)*0.09) which was the first, wrong implementation.
 */
function nearestCentEndingIn(float $rawDollars, array $targetLastDigits): float
{
    $centsRaw = $rawDollars * 100;
    $centsRounded = (int) round($centsRaw);

    $best = null;
    $bestDist = null;
    for ($offset = -9; $offset <= 9; $offset++) {
        $candidate = $centsRounded + $offset;
        $lastDigit = (($candidate % 10) + 10) % 10;
        if (in_array($lastDigit, $targetLastDigits, true)) {
            $dist = abs($centsRaw - $candidate);
            if ($bestDist === null || $dist < $bestDist) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }
    }

    return $best / 100;
}

/**
 * 2x price minus 20%, then rounded to match the source listing's cents ending:
 *   - source ends in .X0 -> round new price to the nearest price ending in 0
 *   - source ends in .X9 -> round new price to the nearest price ending in 9
 *   - anything else -> round to the nearest cent value ending in 0 or 9,
 *     whichever is closer
 */
function computeTwoPackPrice(float $sourcePrice): float
{
    $raw = $sourcePrice * 2 * 0.8;
    $sourceCents = (int) round(($sourcePrice - floor($sourcePrice)) * 100) % 10;

    if ($sourceCents === 0) {
        return nearestCentEndingIn($raw, [0]);
    }
    if ($sourceCents === 9) {
        return nearestCentEndingIn($raw, [9]);
    }

    return nearestCentEndingIn($raw, [0, 9]);
}

/**
 * Quantity is the source quantity divided by 2 (floored — a source qty of 1
 * becomes 0). For a variation listing, apply this PER CHILD and sum, not to
 * the pre-summed total (floor(5/2) + floor(1/2) = 2, not floor(6/2) = 3) —
 * callers must call this once per variation child, never once on a sum.
 */
function twoPackQuantity(int $sourceQuantity): int
{
    return intdiv($sourceQuantity, 2);
}

/**
 * Prefixes "2 Pack " in front of the source title. When that pushes the title
 * past eBay's 80-char cap this does a blunt substr() truncation; callers that
 * need the smarter AI-assisted shrink use two_pack_title_shrink.php instead.
 */
function twoPackTitle(string $sourceTitle): string
{
    return substr('2 Pack ' . $sourceTitle, 0, 80); // eBay hard-caps Title at 80 chars
}
