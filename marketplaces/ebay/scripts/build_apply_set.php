<?php

declare(strict_types=1);

/**
 * build_apply_set.php  —  STAGE 1 of the write-back bridge.  DRY-RUN ONLY.
 *
 * Collapses review_sheet.csv into the FINAL set of item specifics we would write
 * to each eBay listing, applying:
 *   (a) a value-precedence rule  (approved > deterministic > high-certainty LLM), and
 *   (b) the MERGE GUARD: every aspect already live on the listing is preserved,
 *       because eBay's ReviseItem REPLACES THE WHOLE ItemSpecifics set — if we sent
 *       only the new fills, every existing (already-correct, often REQUIRED) aspect
 *       would be wiped. So the apply set = (kept current aspects) ∪ (approved
 *       corrections) ∪ (new fills) − (explicit deletions).
 *
 * NO eBay calls.  Reads review_sheet.csv, writes two artifacts:
 *   apply_set.json     — per listing: {sku, category_id, specifics{aspect:value}, diff}
 *                        `specifics` is the EXACT, COMPLETE set to push (the merged set).
 *   apply_preview.csv  — one row per (listing, aspect) with the action + provenance,
 *                        so a human can eyeball every decision.
 *
 * Usage: php ebay/scripts/build_apply_set.php --account=dows [--threshold=80]
 *   --threshold  minimum certainty for an UNREVIEWED llm fill to be applied (default 80).
 *                Deterministic (usurper/rule/default) fills are always applied.
 *                A human `approved_value` always wins regardless of threshold.
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts      = getopt('', ['account:', 'threshold:']);
$account   = strtolower((string) ($opts['account'] ?? 'dows'));
$threshold = (int) ($opts['threshold'] ?? 80);
$dir       = ebay_dir($account, 'output');

function readCsv(string $path): array
{
    $rows = []; if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

// a "deterministic" (always-trusted) source: Usurper export, rule fill, or safe default
function isDeterministic(string $src): bool
{
    return $src === 'rule' || $src === 'default' || strpos($src, 'usurper') === 0;
}

// advisory SELECTION_ONLY check against the (possibly truncated) allowed list in the
// sheet. Truncated lists (ending '...') can't be trusted → treated as valid here;
// write_back.php MUST re-validate against ebay/data/aspects/{cat}.json before sending.
function allowedOk(string $val, string $mode, string $allowed): bool
{
    if ($mode !== 'SELECTION_ONLY' || $allowed === '' || strpos($allowed, '...') !== false) { return true; }
    $opts = array_map(fn($o) => mb_strtolower(trim($o)), explode('|', $allowed));
    return in_array(mb_strtolower(trim($val)), $opts, true);
}

// ---- group every review row by listing -------------------------------------
$byItem = [];
foreach (readCsv($dir . '/review_sheet.csv') as $r) { $byItem[$r['item_id']][] = $r; }

$apply = [];
$stat  = ['listings' => 0, 'keep' => 0, 'add' => 0, 'change' => 0, 'delete' => 0, 'skip' => 0, 'invalid' => 0];

$pv = fopen($dir . '/apply_preview.csv', 'w');
fputcsv($pv, ['item_id', 'sku', 'aspect', 'action', 'final_value', 'from_value', 'chosen_from', 'mode', 'valid', 'title']);

foreach ($byItem as $id => $rows) {
    $stat['listings']++;
    $sku = ''; $cat = ''; $title = '';
    $specs = [];                                   // the merged set we will write
    $diff  = ['added' => [], 'changed' => [], 'kept' => 0, 'deleted' => []];

    foreach ($rows as $r) {
        if ($sku === '')   { $sku = $r['sku']; }
        if ($cat === '')   { $cat = $r['category_id']; }
        if ($title === '') { $title = $r['title']; }

        $src = $r['source'];
        if ($src === 'variation') { continue; }    // variation-level, NOT parent ItemSpecifics

        $aspect   = $r['aspect'];
        $approved = trim($r['approved_value']);
        $proposed = trim($r['proposed_value']);
        $current  = trim($r['current_value']);
        $mode     = $r['mode'];
        $allowed  = $r['allowed_values'];
        $cert     = (int) ($r['certainty'] !== '' ? $r['certainty'] : 0);

        $final = ''; $chosen = 'none'; $action = 'skip';

        if ($src === 'current') {
            // aspect is LIVE on eBay — keep it unless the human overrode or deleted it.
            // NB: an LLM suggestion sitting in proposed_value is NOT auto-applied over a
            // live value; only a human approved_value may change/remove a live aspect.
            if ($approved !== '') {
                if (strtoupper($approved) === 'DELETE') {
                    $chosen = 'approved:DELETE'; $action = 'delete'; $diff['deleted'][] = $aspect;
                } else {
                    $final = $approved; $chosen = 'approved';
                    if (mb_strtolower($approved) !== mb_strtolower($current)) {
                        $action = 'change'; $diff['changed'][] = ['aspect' => $aspect, 'from' => $current, 'to' => $approved];
                    } else {
                        $action = 'keep'; $diff['kept']++;
                    }
                }
            } else {
                $final = $current; $chosen = 'current'; $action = 'keep'; $diff['kept']++;
            }
        } else {
            // GAP aspect (missing today) — decide whether to fill it.
            if ($approved !== '' && strtoupper($approved) !== 'DELETE') {
                $final = $approved; $chosen = 'approved'; $action = 'add';
            } elseif ($approved !== '' && strtoupper($approved) === 'DELETE') {
                $action = 'skip'; $chosen = 'approved:DELETE';     // reviewer explicitly declined
            } elseif ($proposed !== '' && isDeterministic($src)) {
                $final = $proposed; $chosen = 'deterministic'; $action = 'add';
            } elseif ($proposed !== '' && $src === 'llm' && $cert >= $threshold) {
                $final = $proposed; $chosen = 'llm>=' . $threshold; $action = 'add';
            } else {
                $action = 'skip';                                   // no value / low-cert llm / blank
            }
            if ($action === 'add') { $diff['added'][] = $aspect; }
        }

        // 'blank_value' is rule #5's "intentionally N/A" marker, never a real eBay
        // value — drop it so write-back leaves the aspect blank.
        if (mb_strtolower(trim($final)) === 'blank_value') {
            $final = ''; $action = 'skip';
            if (($k = array_search($aspect, $diff['added'], true)) !== false) { unset($diff['added'][$k]); }
        }

        // tally + collect
        if ($action === 'keep')        { $stat['keep']++; }
        elseif ($action === 'add')     { $stat['add']++; }
        elseif ($action === 'change')  { $stat['change']++; }
        elseif ($action === 'delete')  { $stat['delete']++; }
        else                           { $stat['skip']++; }

        $valid = $final === '' ? true : allowedOk($final, $mode, $allowed);
        if ($final !== '' && !$valid) { $stat['invalid']++; }
        if ($final !== '') { $specs[$aspect] = $final; }           // omission = removal (merge guard)

        fputcsv($pv, [$id, $sku, $aspect, $action, $final, $src === 'current' ? $current : '', $chosen, $mode, $valid ? 'yes' : 'NO', $title]);
    }

    $apply[$id] = ['sku' => $sku, 'category_id' => $cat, 'specifics' => $specs, 'diff' => $diff];
}
fclose($pv);

file_put_contents($dir . '/apply_set.json',
    json_encode($apply, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$specCount = array_sum(array_map(fn($a) => count($a['specifics']), $apply));
echo "wrote {$dir}/apply_set.json  +  {$dir}/apply_preview.csv\n";
printf("  %d listings → %d total specifics to write\n", $stat['listings'], $specCount);
printf("  keep(existing): %d  add(new fill): %d  change(reviewer): %d  delete(reviewer): %d  skip: %d\n",
    $stat['keep'], $stat['add'], $stat['change'], $stat['delete'], $stat['skip']);
printf("  SELECTION_ONLY values failing the (advisory) allowed-list check: %d\n", $stat['invalid']);
