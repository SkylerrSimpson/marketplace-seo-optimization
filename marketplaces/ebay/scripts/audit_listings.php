<?php

declare(strict_types=1);

/**
 * PHASE 5 — gap audit. For each enumerated+enriched listing, diff the Item
 * Specifics it currently has (marketplaces/ebay/data/{account}/output/items/{id}.json) against
 * the aspect schema for its leaf category (marketplaces/ebay/data/aspects/{catId}.json), and
 * emit a priority-scored list of what's MISSING.
 *
 * A "gap" = an aspect the category schema defines that the listing does not have.
 * Required-but-missing is weighted far above recommended-but-missing.
 *   priority = missing_required * 5 + missing_recommended
 *
 * Outputs (per account, under marketplaces/ebay/data/{account}/output/):
 *   aspect_gaps_summary.csv   one row per listing: counts + the missing names,
 *                             sorted by priority (worst first). The review file.
 *   aspect_gaps_worklist.csv  one row per (listing x missing aspect): the actual
 *                             fill worklist for P6, carrying the fill constraints
 *                             (importance, FREE_TEXT vs SELECTION_ONLY, cardinality,
 *                             allowed-value count + sample) so fills stay valid.
 *
 * Read-only (consumes local JSON only — no eBay calls).
 *
 * Usage:
 *   php marketplaces/ebay/scripts/audit_listings.php                # both accounts
 *   php marketplaces/ebay/scripts/audit_listings.php --account=dows
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts = getopt('', ['account:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php audit_listings.php [--account=dows|ige]\n");
    exit(0);
}
$accounts = isset($opts['account']) ? [strtolower((string) $opts['account'])] : ['dows', 'ige'];

/** Normalize an aspect name for matching (case/space/punct-insensitive-ish). */
function norm(string $s): string
{
    return preg_replace('/\s+/', ' ', trim(mb_strtolower($s)));
}

// Schema cache: catId => [normName => aspectMeta], loaded lazily.
$schemaCache = [];
function loadSchema(string $catId, array &$cache): ?array
{
    if (array_key_exists($catId, $cache)) { return $cache[$catId]; }
    $path = EBAY_ASPECTS . "/{$catId}.json";
    if (!is_file($path)) { return $cache[$catId] = null; }
    $j = json_decode((string) file_get_contents($path), true) ?: [];
    $byName = [];
    foreach ($j['aspects'] ?? [] as $a) {
        $byName[norm((string) $a['name'])] = $a;
    }
    return $cache[$catId] = $byName;
}

foreach ($accounts as $account) {
    $itemDir = ebay_dir($account, 'output') . '/items';
    if (!is_dir($itemDir)) { fwrite(STDERR, "No items dir for {$account}; run enrich_listings.php first.\n"); continue; }

    $summaryRows = [];
    $worklistRows = [];
    $audited = 0; $unauditable = 0; $noSchema = 0;
    $gapReq = 0; $gapRec = 0;
    $catGap = [];   // category_path => [req, rec, listings]

    foreach (glob($itemDir . '/*.json') as $file) {
        $it = json_decode((string) file_get_contents($file), true);
        if (!is_array($it)) { continue; }
        if (($it['status'] ?? '') !== 'OK' || ($it['category_id'] ?? '') === '') { $unauditable++; continue; }

        $schema = loadSchema((string) $it['category_id'], $schemaCache);
        if ($schema === null) { $noSchema++; continue; }
        $audited++;

        // present aspect names (normalized)
        $present = [];
        foreach (($it['aspects'] ?? []) as $name => $_v) { $present[norm((string) $name)] = true; }

        $missReq = []; $missRec = [];
        foreach ($schema as $nname => $a) {
            if (isset($present[$nname])) { continue; }   // listing already has it
            $isReq = (bool) ($a['required'] ?? false);
            if ($isReq) { $missReq[] = $a['name']; } else { $missRec[] = $a['name']; }

            $worklistRows[] = [
                'item_id'        => $it['item_id'],
                'category_id'    => $it['category_id'],
                'aspect'         => $a['name'],
                'importance'     => $isReq ? 'required' : 'recommended',
                'mode'           => $a['mode'] ?? '',
                'cardinality'    => $a['cardinality'] ?? '',
                'allowed_values' => count($a['values'] ?? []),
                'values_sample'  => implode(' | ', array_slice($a['values'] ?? [], 0, 6)),
                'title'          => $it['title'] ?? '',
            ];
        }

        $priority = count($missReq) * 5 + count($missRec);
        $gapReq += count($missReq); $gapRec += count($missRec);

        $cp = $it['category_path'] ?? $it['category_id'];
        $catGap[$cp] ??= ['req' => 0, 'rec' => 0, 'listings' => 0];
        $catGap[$cp]['req'] += count($missReq);
        $catGap[$cp]['rec'] += count($missRec);
        $catGap[$cp]['listings']++;

        $summaryRows[] = [
            'priority'             => $priority,
            'item_id'              => $it['item_id'],
            'title'                => $it['title'] ?? '',
            'category_id'          => $it['category_id'],
            'category_path'        => $cp,
            'schema_aspects'       => count($schema),
            'present'              => count($schema) - count($missReq) - count($missRec),
            'missing_required'     => count($missReq),
            'missing_recommended'  => count($missRec),
            'missing_required_names'    => implode('; ', $missReq),
            'missing_recommended_names' => implode('; ', $missRec),
        ];
    }

    // sort worst-first
    usort($summaryRows, fn($a, $b) => $b['priority'] <=> $a['priority']);
    usort($worklistRows, function ($a, $b) {
        if ($a['importance'] !== $b['importance']) { return $a['importance'] === 'required' ? -1 : 1; }
        return $b['allowed_values'] <=> $a['allowed_values'];
    });

    // write summary
    $sPath = ebay_dir($account, 'output') . '/aspect_gaps_summary.csv';
    $fh = fopen($sPath, 'w');
    fputcsv($fh, ['priority', 'item_id', 'title', 'category_id', 'category_path', 'schema_aspects', 'present', 'missing_required', 'missing_recommended', 'missing_required_names', 'missing_recommended_names']);
    foreach ($summaryRows as $r) { fputcsv($fh, $r); }
    fclose($fh);

    // write worklist
    $wPath = ebay_dir($account, 'output') . '/aspect_gaps_worklist.csv';
    $fh = fopen($wPath, 'w');
    fputcsv($fh, ['item_id', 'category_id', 'aspect', 'importance', 'mode', 'cardinality', 'allowed_values', 'values_sample', 'title']);
    foreach ($worklistRows as $r) { fputcsv($fh, [$r['item_id'], $r['category_id'], $r['aspect'], $r['importance'], $r['mode'], $r['cardinality'], $r['allowed_values'], $r['values_sample'], $r['title']]); }
    fclose($fh);

    // console roll-up
    echo "=== {$account} ===\n";
    echo "audited: {$audited}, unauditable: {$unauditable}, missing-schema: {$noSchema}\n";
    echo "gaps: missing-required {$gapReq}, missing-recommended {$gapRec}, total " . ($gapReq + $gapRec) . "\n";
    echo "listings fully complete (0 gaps): " . count(array_filter($summaryRows, fn($r) => $r['priority'] === 0)) . " / {$audited}\n";
    uasort($catGap, fn($a, $b) => ($b['req'] * 5 + $b['rec']) <=> ($a['req'] * 5 + $a['rec']));
    echo "top gap categories (by weighted gaps):\n";
    foreach (array_slice($catGap, 0, 8, true) as $cp => $g) {
        printf("  req %-4d rec %-5d over %-4d listings  %s\n", $g['req'], $g['rec'], $g['listings'], substr($cp, 0, 52));
    }
    echo "  -> {$sPath}\n  -> {$wPath}\n\n";
}
