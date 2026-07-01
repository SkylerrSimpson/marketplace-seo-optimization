<?php
declare(strict_types=1);
/**
 * Enumerate every product video (EXTERNAL_VIDEO) with its real YouTube ID +
 * the product it lives on, so we can build the video watch-page list.
 * Read-only. Writes shopify/data/drafts/video_master_list.csv
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

Context::initialize(apiKey:'x', apiSecretKey:'x', scopes:['read_products'],
  hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'),
  apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

const Q='query($cursor:String){ products(first:50, after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ title handle media(first:25){ nodes{ mediaContentType ... on ExternalVideo{ embedUrl originUrl } ... on Video{ sources{ url } } } } } } }';

function ytid($url){
  if(!$url) return '';
  if(preg_match('~(?:v=|/embed/|youtu\.be/|/vi/)([A-Za-z0-9_-]{11})~',$url,$m)) return $m[1];
  return '';
}

$rows=[]; $cursor=null;
do{
  $r=$c->query(['query'=>Q,'variables'=>['cursor'=>$cursor]])->getDecodedBody();
  if(isset($r['errors'])){ fwrite(STDERR,"ERR ".json_encode($r['errors'])."\n"); exit(1); }
  $d=$r['data']['products'];
  foreach($d['nodes'] as $n){
    foreach($n['media']['nodes'] as $mn){
      if(($mn['mediaContentType']??'')!=='EXTERNAL_VIDEO') continue;
      $id=ytid($mn['embedUrl']??'') ?: ytid($mn['originUrl']??'');
      if($id==='') continue;
      $rows[$id][]=$n['title'];
    }
  }
  $cursor=$d['pageInfo']['hasNextPage']?$d['pageInfo']['endCursor']:null;
}while($cursor);

// 3 that only appear on the current Videos page
foreach(['5ZUFmtFPgdE','pXoZAJsz4qo','UxDOZeiHAes'] as $id)
  if(!isset($rows[$id])) $rows[$id][]='(Videos page only)';

$pf=fopen(__DIR__.'/../data/drafts/video_master_list.csv','w');
fputcsv($pf,['youtube_id','watch_url','on_products']);
ksort($rows);
foreach($rows as $id=>$titles){
  fputcsv($pf,[$id,"https://www.youtube.com/watch?v=$id",implode(' | ',array_unique($titles))]);
}
fclose($pf);
echo "unique videos: ".count($rows)."\n";
echo "CSV: shopify/data/drafts/video_master_list.csv\n";
foreach($rows as $id=>$titles) echo "  $id  <-  ".implode(' | ',array_unique($titles))."\n";
