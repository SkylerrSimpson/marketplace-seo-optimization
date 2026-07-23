<?php

declare(strict_types=1);

/**
 * Shipping-policy audit — READ ONLY, no writes to any listing.
 *
 * Finds listings priced under $20 that are still on an "Under 1lb" shipping
 * policy, so they can be moved to the "Sub $15 Listing Shipping Surcharge Flat
 * Fee" policy instead. This script identifies the candidates;
 * it does not change anything.
 *
 * Why this shape: the roster (item_id, price, sku, variations) already exists
 * in listings.json (export_listings.php, Sell Feed API). What's missing is the
 * per-listing shipping policy assignment, which only Trading GetItem exposes
 * (Item.SellerProfiles.SellerShippingProfile). Earlier docs in this codebase
 * noted Trading GetItem as Akamai edge-blocked from this network; a live check
 * today (2026-07-16) shows GetItem now succeeds reliably, so this script uses
 * it directly rather than working around it.
 *
 * Flow:
 *   1. GetSellerProfiles (BusinessPoliciesManagement API) -> id/name map of every
 *      shipping policy on the account (read-only).
 *   2. Load listings.json, filter to parent items whose price (or, for
 *      variation listings, cheapest variation price) is under --price-under.
 *      Shipping policy is assigned at the PARENT item, not per-variation, so
 *      only one GetItem call is needed per parent regardless of variation count.
 *   3. Trading GetItem per candidate -> live CurrentPrice + assigned
 *      ShippingProfileID/Name (re-validates the cached price at the same time,
 *      since listings.json can be stale).
 *   4. Flag rows whose CURRENT policy name matches --policy-contains (default
 *      "under 1lb", case-insensitive) AND whose live price is under the
 *      threshold. The target policy to move flagged rows to is NOT repeated
 *      per row (it's the same for every flagged row) — it's printed once at
 *      the top of the run and lives in shipping_policies.csv
 *      (matches_target_pattern column); apply_shipping_policy.php takes it as
 *      an explicit --target-policy-id, it doesn't guess it from this file.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/audit_shipping_policy.php --account=dows
 *   php marketplaces/ebay/scripts/audit_shipping_policy.php --account=dows --price-under=20 --policy-contains="under 1lb" --target-contains="sub $15"
 *   php marketplaces/ebay/scripts/audit_shipping_policy.php --account=dows --limit=20   # smoke test a subset first
 *
 * Output (under marketplaces/ebay/data/{account}/output/):
 *   shipping_policy_audit.csv   one row per checked candidate:
 *                                item_id, sku, title, is_variation, live_price,
 *                                package_weight_lb, policy_id, policy_name,
 *                                matches_criteria, status
 *   shipping_policies.csv       full id/name list of every shipping policy on the account
 *
 * package_weight_lb comes from the same GetItem call already made for price/policy
 * (Item.ShippingPackageDetails.WeightMajor/WeightMinor, lb+oz -> decimal lb) — no
 * extra API call. Blank if the listing has no package weight set at all.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\BusinessPoliciesManagement\Services\BusinessPoliciesManagementService;
use DTS\eBaySDK\BusinessPoliciesManagement\Types\GetSellerProfilesRequest;
use DTS\eBaySDK\Trading\Types\GetItemRequestType;

$opts = getopt('', [
    'account:', 'price-under:', 'policy-contains:', 'target-contains:', 'limit:', 'resume', 'help',
]);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php audit_shipping_policy.php --account=dows|ige [--price-under=20] [--policy-contains=\"under 1lb\"] [--target-contains=\"sub \$15\"] [--limit=N] [--resume]\n");
    exit(0);
}

$account        = strtolower((string) ($opts['account'] ?? 'dows'));
$priceUnder     = (float) ($opts['price-under'] ?? 20);
$policyContains = mb_strtolower((string) ($opts['policy-contains'] ?? 'under 1lb'));
$targetContains = mb_strtolower((string) ($opts['target-contains'] ?? 'sub $15'));
$limit          = isset($opts['limit']) ? (int) $opts['limit'] : null;

$client = new EbayClient($account);
$outDir = ebay_dir($account, 'output');

echo "=== shipping policy audit: {$account} ({$client->env()}) ===\n";
echo "price threshold: < \${$priceUnder} | current-policy match: \"{$policyContains}\" | suggested target match: \"{$targetContains}\"\n\n";

// --- Step 1: pull every shipping policy on the account -------------------------
echo "Fetching shipping policies (BusinessPoliciesManagement GetSellerProfiles)...\n";
$bpm = new BusinessPoliciesManagementService([
    'credentials' => [
        'appId'  => (string) $client->cred('app_id'),
        'certId' => (string) $client->cred('cert_id'),
        'devId'  => (string) ($client->cred('dev_id') ?? ''),
    ],
    'authToken' => $client->userToken(),
    'globalId'  => 'EBAY-US',
    'sandbox'   => $client->isSandbox(),
]);
// Same intermittent Akamai "Zero size object" 503 documented elsewhere in this
// codebase for Trading; retry a couple times with backoff before giving up.
$policyResp = null;
$lastError = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        $policyResp = $bpm->getSellerProfiles(new GetSellerProfilesRequest([
            'profileType'    => ['SHIPPING'],
            'includeDetails' => false,
        ]));
        break;
    } catch (Throwable $e) {
        $lastError = $e;
        echo "  [retry {$attempt}/3] GetSellerProfiles failed: " . $e->getMessage() . "\n";
        sleep(5 * $attempt);
    }
}
if ($policyResp === null) {
    fwrite(STDERR, "  [ERR] GetSellerProfiles failed after 3 attempts: " . $lastError->getMessage() . "\n");
    exit(1);
}
if ((string) $policyResp->ack !== 'Success') {
    fwrite(STDERR, "  [ERR] GetSellerProfiles ack=" . $policyResp->ack . "\n");
    exit(1);
}
$policies = [];
foreach ($policyResp->shippingPolicyProfile->ShippingPolicyProfile ?? [] as $p) {
    $policies[(string) $p->profileId] = (string) $p->profileName;
}
echo "  " . count($policies) . " shipping policies found\n";

$policiesCsv = $outDir . '/shipping_policies.csv';
$fh = fopen($policiesCsv, 'w');
fputcsv($fh, ['profile_id', 'profile_name', 'matches_current_pattern', 'matches_target_pattern']);
foreach ($policies as $id => $name) {
    fputcsv($fh, [
        $id,
        $name,
        str_contains(mb_strtolower($name), $policyContains) ? 'yes' : '',
        str_contains(mb_strtolower($name), $targetContains) ? 'yes' : '',
    ]);
}
fclose($fh);
echo "  {$policiesCsv}\n\n";

$targetCandidates = array_filter($policies, fn($name) => str_contains(mb_strtolower($name), $targetContains));
if ($targetCandidates === []) {
    fwrite(STDERR, "  [WARN] no shipping policy name matches target pattern \"{$targetContains}\" — check shipping_policies.csv and re-run with --target-contains if needed.\n");
} else {
    echo "  target-policy candidates (" . count($targetCandidates) . "): " . implode(' | ', array_map(fn($id, $n) => "{$n} ({$id})", array_keys($targetCandidates), $targetCandidates)) . "\n\n";
}

// --- Step 2: load roster, pick candidates by cached price -----------------------
$listingsPath = $outDir . '/listings.json';
if (!is_file($listingsPath)) {
    fwrite(STDERR, "  [ERR] {$listingsPath} not found — run export_listings.php first.\n");
    exit(1);
}
$listings = json_decode((string) file_get_contents($listingsPath), true) ?? [];
echo "Loaded " . count($listings) . " listings from " . basename($listingsPath) . " (mtime: " . date('Y-m-d H:i', filemtime($listingsPath)) . ")\n";

$candidates = [];
foreach ($listings as $l) {
    $prices = [];
    if ($l['price'] !== '' && $l['price'] !== null) {
        $prices[] = (float) $l['price'];
    }
    foreach ($l['variations'] ?? [] as $v) {
        if ($v['price'] !== '' && $v['price'] !== null) {
            $prices[] = (float) $v['price'];
        }
    }
    if ($prices === []) {
        continue;
    }
    $minPrice = min($prices);
    $maxPrice = max($prices);
    if ($minPrice < $priceUnder) {
        $candidates[] = [
            'item_id'          => (string) $l['item_id'],
            'sku'              => (string) $l['sku'],
            'is_variation'     => $l['variations'] !== [] ? 1 : 0,
            'cached_min_price' => $minPrice,
            'cached_max_price' => $maxPrice,
        ];
    }
}
$auditCsv = $outDir . '/shipping_policy_audit.csv';
$alreadyDone = [];
$resume = isset($opts['resume']) && is_file($auditCsv);
if ($resume) {
    $rh = fopen($auditCsv, 'r');
    $header = fgetcsv($rh);
    while (($row = fgetcsv($rh)) !== false) {
        $alreadyDone[$row[0]] = true;
    }
    fclose($rh);
    $candidates = array_values(array_filter($candidates, fn($c) => !isset($alreadyDone[$c['item_id']])));
    echo "Resuming: " . count($alreadyDone) . " already checked, " . count($candidates) . " remaining\n";
}
if ($limit !== null) {
    $candidates = array_slice($candidates, 0, $limit);
}
echo "Candidates (cached min price < \${$priceUnder}): " . count($candidates) . "\n";
echo "Checking live price + shipping policy per candidate via Trading GetItem (this will take a while)...\n\n";

// --- Step 3+4: GetItem per candidate, flag ---------------------------------------
$fh = fopen($auditCsv, $resume ? 'a' : 'w');
if (!$resume) {
    fputcsv($fh, [
        'item_id', 'sku', 'title', 'is_variation', 'live_price', 'package_weight_lb', 'policy_id', 'policy_name',
        'matches_criteria', 'status',
    ]);
}

$flaggedCount = 0;
$errorCount   = 0;
$i = 0;
foreach ($candidates as $c) {
    $i++;
    $itemId = $c['item_id'];

    $req = new GetItemRequestType();
    $req->ItemID = $itemId;
    $req->DetailLevel = ['ReturnAll'];

    $title = '';
    $packageWeight = '';
    $livePrice = '';
    $priceSource = '';
    $policyId = '';
    $policyName = '';
    $status = 'ok';
    $error = '';

    try {
        $resp = $client->trading()->getItem($req);
        if ((string) $resp->Ack === 'Failure') {
            $status = 'error';
            $msgs = [];
            foreach ($resp->Errors ?? [] as $e) {
                $msgs[] = '[' . ($e->ErrorCode ?? '?') . '] ' . ($e->LongMessage ?? $e->ShortMessage ?? '');
            }
            $error = implode(' | ', $msgs);
            $errorCount++;
        } else {
            $item = $resp->Item;
            $title = (string) ($item->Title ?? '');
            if (isset($item->Variations) && count($item->Variations->Variation ?? []) > 0) {
                $varPrices = [];
                foreach ($item->Variations->Variation as $v) {
                    if (isset($v->StartPrice->value)) {
                        $varPrices[] = (float) $v->StartPrice->value;
                    }
                }
                $livePrice = $varPrices !== [] ? (string) min($varPrices) : '';
                $priceSource = 'min_variation';
            } elseif (isset($item->SellingStatus->CurrentPrice->value)) {
                $livePrice = (string) $item->SellingStatus->CurrentPrice->value;
                $priceSource = 'current_price';
            }
            if (isset($item->SellerProfiles->SellerShippingProfile)) {
                $sp = $item->SellerProfiles->SellerShippingProfile;
                $policyId = (string) ($sp->ShippingProfileID ?? '');
                $policyName = (string) ($sp->ShippingProfileName ?? '');
            }
            if (isset($item->ShippingPackageDetails)) {
                $spd = $item->ShippingPackageDetails;
                $major = $spd->WeightMajor->value ?? null;
                $minor = $spd->WeightMinor->value ?? null;
                if ($major !== null || $minor !== null) {
                    $packageWeight = (string) round(((float) ($major ?? 0)) + ((float) ($minor ?? 0)) / 16, 3);
                }
            }
        }
    } catch (Throwable $e) {
        $status = 'exception';
        $error = get_class($e) . ': ' . $e->getMessage();
        $errorCount++;
    }

    $matchesCurrent = $policyName !== '' && str_contains(mb_strtolower($policyName), $policyContains);
    $flagged = $matchesCurrent && $livePrice !== '' && (float) $livePrice < $priceUnder;
    if ($flagged) {
        $flaggedCount++;
    }

    $statusOut = $status === 'ok' ? 'ok' : trim("{$status}: {$error}");

    fputcsv($fh, [
        $itemId, $c['sku'], $title, $c['is_variation'], $livePrice, $packageWeight, $policyId, $policyName,
        $flagged ? 'yes' : '', $statusOut,
    ]);
    fflush($fh);

    if ($i % 5 === 0 || $i === count($candidates)) {
        echo "  {$i}/" . count($candidates) . " checked | flagged so far: {$flaggedCount} | errors: {$errorCount}\n";
    }

    usleep(600000); // ~1.7 req/sec — well under Trading's per-call rate limit
}
fclose($fh);

echo "\n========================================\n";
echo "checked: " . count($candidates) . " | flagged (under \${$priceUnder} AND on a \"{$policyContains}\" policy): {$flaggedCount} | errors: {$errorCount}\n";
echo "  {$auditCsv}\n";
