<?php
declare(strict_types=1);
/**
 * Pull real title, description, upload date, and duration for all 20 videos
 * from the YouTube Data API v3, using the IDs in video_master_list.csv.
 * Read-only. Writes:
 *   shopify/data/drafts/video_metadata.json   (machine — feeds the build script)
 *   shopify/data/drafts/video_metadata.csv    (human review)
 *
 * Duration comes back ISO-8601 (PT2M34S) = exactly what VideoObject wants.
 */
require __DIR__ . '/../../lib/bootstrap.php';

$key = $_ENV['YOUTUBE_API_KEY'] ?? getenv('YOUTUBE_API_KEY');
if (!$key) { fwrite(STDERR, "YOUTUBE_API_KEY missing from .env\n"); exit(1); }

// read IDs from the master list
$src = __DIR__ . '/../data/drafts/video_master_list.csv';
$fh = fopen($src, 'r'); fgetcsv($fh); // header
$ids = []; $onProducts = [];
while (($r = fgetcsv($fh)) !== false) { $ids[] = $r[0]; $onProducts[$r[0]] = $r[2] ?? ''; }
fclose($fh);

// pretty ISO-8601 duration -> m:ss for humans
function human_dur(string $iso): string {
  if (!preg_match('~PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?~', $iso, $m)) return '';
  $h=(int)($m[1]??0); $min=(int)($m[2]??0); $s=(int)($m[3]??0);
  if ($h) return sprintf('%d:%02d:%02d',$h,$min,$s);
  return sprintf('%d:%02d',$min,$s);
}

$out = [];
// videos.list takes up to 50 ids per call
foreach (array_chunk($ids, 50) as $chunk) {
  $url = 'https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails&id='
       . implode(',', $chunk) . '&key=' . urlencode($key);
  $resp = @file_get_contents($url);
  if ($resp === false) { fwrite(STDERR, "HTTP fetch failed\n"); exit(1); }
  $data = json_decode($resp, true);
  if (isset($data['error'])) { fwrite(STDERR, "API ERR: ".json_encode($data['error'])."\n"); exit(1); }
  foreach ($data['items'] ?? [] as $it) {
    $id = $it['id'];
    $sn = $it['snippet'] ?? [];
    $cd = $it['contentDetails'] ?? [];
    $out[$id] = [
      'youtube_id'  => $id,
      'title'       => $sn['title'] ?? '',
      'description' => $sn['description'] ?? '',
      'upload_date' => isset($sn['publishedAt']) ? substr($sn['publishedAt'],0,10) : '',
      'duration'    => $cd['duration'] ?? '',
      'duration_h'  => human_dur($cd['duration'] ?? ''),
      'on_products' => $onProducts[$id] ?? '',
    ];
  }
}

// report any IDs the API didn't return (private/deleted/wrong id)
$missing = array_diff($ids, array_keys($out));
foreach ($missing as $m) fwrite(STDERR, "  !! no data returned for: $m\n");

// keep original order
$ordered = [];
foreach ($ids as $id) if (isset($out[$id])) $ordered[] = $out[$id];

file_put_contents(__DIR__.'/../data/drafts/video_metadata.json',
  json_encode($ordered, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

$pf = fopen(__DIR__.'/../data/drafts/video_metadata.csv','w');
fputcsv($pf, ['youtube_id','title','upload_date','length','duration_iso','description(first 120)','on_products']);
foreach ($ordered as $v) {
  fputcsv($pf, [$v['youtube_id'],$v['title'],$v['upload_date'],$v['duration_h'],$v['duration'],
    mb_substr(str_replace(["\r","\n"],' ',$v['description']),0,120), $v['on_products']]);
}
fclose($pf);

echo "pulled: ".count($ordered)." / ".count($ids)."\n";
if ($missing) echo "MISSING: ".implode(', ',$missing)."\n";
echo "JSON: shopify/data/drafts/video_metadata.json\n";
echo "CSV : shopify/data/drafts/video_metadata.csv\n";
