<?php

declare(strict_types=1);


require_once __DIR__ . '/../lib/bootstrap.php';

$opts = getopt('', ['account:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php build_image_review.php --account=<account_name>\n";
    exit(0);
}
if (!isset($opts['account'])) {
    echo "Error: --account option is required.\n";
    exit(1);
}

$account = $opts['account'];
$outDir = ebay_dir($account, 'output');
$mediaDir = $outDir . '/media';
$descDir  = $outDir . '/descriptions';
if (!is_dir($mediaDir)) { mkdir($mediaDir, 0777, true); }

$STORES = [
    'dows' => ['brand' => 'Deals Only Webstore',  'slug' => 'dealsonlywebstore'],
    'ige'  => ['brand' => 'Irongate Enterprises', 'slug' => 'irongateamericansupply'],
];
$store = $STORES[$account] ?? $STORES['dows'];

$packPath = $outDir . '/media_source_pack.jsonl';
if (!file_exists($packPath)) {
    echo "Error: media_source_pack.jsonl not found in $outDir\n";
    exit(1);
}





