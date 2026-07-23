<?php
declare(strict_types=1);
/**
 * Merge hand-authored watch-page copy (video_watch_pages_authored.json) with
 * YouTube-pulled metadata (video_metadata.json) into one CSV for human review
 * before build_video_watch_pages.php creates the actual blog articles.
 * Read-only. Flags meta_title > 60 chars and meta_description > 165 chars.
 *
 * Prerequisites: data/drafts/video_watch_pages_authored.json (hand-authored
 * copy) and data/drafts/video_metadata.json (from pull_youtube_meta.php)
 * must already exist.
 *
 * Usage: php marketplaces/shopify/scripts/build_video_review_csv.php
 * Writes: marketplaces/shopify/data/drafts/video_watch_pages_review.csv
 */
$dir = __DIR__ . '/../data/drafts/';
$authored = json_decode(file_get_contents($dir.'video_watch_pages_authored.json'), true);
$metaList = json_decode(file_get_contents($dir.'video_metadata.json'), true);

$meta = [];
foreach ($metaList as $m) $meta[$m['youtube_id']] = $m;

$pf = fopen($dir.'video_watch_pages_review.csv', 'w');
fputcsv($pf, ['#','youtube_id','watch_page_title','handle','mt_len','md_len','flags',
  'meta_title','meta_description','on_page_body','upload_date','duration_iso','length']);

$i = 0; $warn = 0;
foreach ($authored as $a) {
  $i++;
  $id = $a['youtube_id'];
  $m  = $meta[$id] ?? [];
  $mtLen = mb_strlen($a['meta_title']);
  $mdLen = mb_strlen($a['meta_description']);
  $flags = [];
  if ($mtLen > 60)  { $flags[] = 'MT_OVER_60'; $warn++; }
  if ($mdLen > 165) { $flags[] = 'MD_OVER_165'; $warn++; }
  if (empty($m['upload_date'])) $flags[] = 'NO_DATE';
  if (empty($m['duration']))    $flags[] = 'NO_DURATION';
  fputcsv($pf, [
    $i, $id, $a['title'], $a['handle'], $mtLen, $mdLen, implode(' ',$flags),
    $a['meta_title'], $a['meta_description'], $a['body'],
    $m['upload_date'] ?? '', $m['duration'] ?? '', $m['duration_h'] ?? '',
  ]);
}
fclose($pf);
echo "rows: $i   length warnings: $warn\n";
echo "CSV: shopify/data/drafts/video_watch_pages_review.csv\n";
