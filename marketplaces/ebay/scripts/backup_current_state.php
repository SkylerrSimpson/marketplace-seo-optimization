<?php

declare(strict_types=1);

/**
 * backup_current_state.php — read-only snapshot of an account's current live
 * eBay state (aspects and/or description HTML), taken immediately before a
 * write so there's always something to revert to. Built for DOWScripts'
 * backup-required write gate (BackupChecker::hasBackupFor()) — that gate is
 * deliberately coarse (any non-empty backups/ dir for the account satisfies
 * it), so this script's job is just to make sure a fresh one always exists,
 * not to prove it covers any particular write.
 *
 * Single Trading GetItem call per listing gets both Item.Description and
 * ItemSpecifics in one round trip (see build_current_live_attributes.php for
 * the aspects-extraction shape this mirrors, and merge_legacy_template.php's
 * companion backup pass from 2026-07-17 for the description shape).
 *
 * No writes to eBay. Idempotent in the sense that each run gets its own
 * timestamped directory — never overwrites a prior backup.
 *
 * Usage:
 *   php ebay/scripts/backup_current_state.php --account=dows
 *   php ebay/scripts/backup_current_state.php --account=ige --what=descriptions
 *   php ebay/scripts/backup_current_state.php --account=dows --what=aspects --limit=5
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;

$opts = getopt('', ['account:', 'what:', 'limit:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php backup_current_state.php --account=dows|ige [--what=aspects|descriptions|all] [--limit=N]\n");
    exit(0);
}

$account = strtolower((string) ($opts['account'] ?? 'dows'));
$what    = strtolower((string) ($opts['what'] ?? 'all'));
if (! in_array($what, ['aspects', 'descriptions', 'all'], true)) {
    fwrite(STDERR, "--what must be one of: aspects, descriptions, all\n");
    exit(1);
}
$limit = isset($opts['limit']) ? (int) $opts['limit'] : null;

$client = new EbayClient($account);
$outDir = ebay_dir($account, 'output');

$rosterPath = $outDir . '/listings.json';
if (! is_file($rosterPath)) {
    fwrite(STDERR, "No roster at {$rosterPath}. Run export_listings.php --account={$account} first.\n");
    exit(1);
}
$roster = json_decode((string) file_get_contents($rosterPath), true) ?: [];
$itemIds = array_values(array_unique(array_map(fn ($l) => (string) $l['item_id'], $roster)));
if ($limit !== null) {
    $itemIds = array_slice($itemIds, 0, $limit);
}

$backupDir = dirname($outDir) . '/backups/dowscripts_' . $what . '_' . date('Y-m-d_Hi');
$wantsAspects = $what === 'aspects' || $what === 'all';
$wantsDescriptions = $what === 'descriptions' || $what === 'all';
if ($wantsAspects) {
    mkdir("{$backupDir}/items", 0775, true);
}
if ($wantsDescriptions) {
    mkdir("{$backupDir}/descriptions", 0775, true);
}

echo "=== backup_current_state: {$account} (what={$what}) — " . count($itemIds) . " listings ===\n\n";

$ok = 0;
$err = 0;
$i = 0;
foreach ($itemIds as $itemId) {
    $i++;
    $req = new GetItemRequestType();
    $req->ItemID = $itemId;
    $req->DetailLevel = ['ReturnAll'];
    $req->IncludeItemSpecifics = true;

    try {
        $resp = $client->trading()->getItem($req);
        if ((string) $resp->Ack === 'Failure') {
            $err++;
        } else {
            $item = $resp->Item;

            if ($wantsAspects) {
                $aspects = [];
                if (isset($item->ItemSpecifics)) {
                    foreach ($item->ItemSpecifics->NameValueList as $nv) {
                        $vals = [];
                        foreach ($nv->Value as $v) { $vals[] = $v; }
                        $aspects[$nv->Name] = implode('; ', $vals);
                    }
                }
                file_put_contents("{$backupDir}/items/{$itemId}.json", json_encode([
                    'item_id' => $itemId,
                    'sku' => (string) ($item->SKU ?? ''),
                    'title' => (string) ($item->Title ?? ''),
                    'category_id' => (string) ($item->PrimaryCategory->CategoryID ?? ''),
                    'aspects' => $aspects,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            if ($wantsDescriptions) {
                file_put_contents("{$backupDir}/descriptions/{$itemId}.html", (string) ($item->Description ?? ''));
            }

            $ok++;
        }
    } catch (\Throwable $e) {
        $err++;
    }

    if ($i % 25 === 0 || $i === count($itemIds)) {
        echo "  {$i}/" . count($itemIds) . " (ok={$ok} err={$err})\n";
    }
    usleep(300000);
}

file_put_contents("{$backupDir}/manifest.json", json_encode([
    'created_at' => date('c'),
    'account' => $account,
    'what' => $what,
    'item_count' => $ok,
    'error_count' => $err,
    'tool' => 'backup_current_state.php',
], JSON_PRETTY_PRINT));

echo "\ndone: {$ok} backed up, {$err} errors (out of " . count($itemIds) . ")\n";
echo "backup saved to: {$backupDir}\n";
