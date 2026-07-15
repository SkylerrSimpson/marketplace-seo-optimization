<?php
declare(strict_types=1);
/**
 * Pull real YouTube view counts for the 20 videos and store each on its
 * watch-page article as custom.view_count (number_integer) so the sort
 * dropdown can order by popularity. Re-run anytime to refresh the counts.
 *
 * DRY-RUN unless --apply. Needs write_content (+ YOUTUBE_API_KEY).
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
$key = $_ENV['YOUTUBE_API_KEY'] ?? getenv('YOUTUBE_API_KEY');
if (!$key) { fwrite(STDERR,"YOUTUBE_API_KEY missing\n"); exit(1); }

Context::initialize(apiKey:'x',apiSecretKey:'x',scopes:['write_content'],hostName:$_ENV['SHOP_DOMAIN'],
  sessionStorage:new FileSessionStorage('/tmp/php_sessions'),apiVersion:$_ENV['API_VERSION']??'2026-04',isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

$dir=__DIR__.'/../data/drafts/';
$authored=json_decode(file_get_contents($dir.'video_watch_pages_authored.json'),true);
$ids=array_column($authored,'youtube_id');
$handleOf=[]; foreach($authored as $a) $handleOf[$a['youtube_id']]=$a['handle'];

// pull view counts
$views=[];
foreach(array_chunk($ids,50) as $chunk){
  $u='https://www.googleapis.com/youtube/v3/videos?part=statistics&id='.implode(',',$chunk).'&key='.urlencode($key);
  $d=json_decode(@file_get_contents($u),true);
  if(isset($d['error'])){ fwrite(STDERR,"API ERR ".json_encode($d['error'])."\n"); exit(1); }
  foreach($d['items']??[] as $it) $views[$it['id']]=(int)($it['statistics']['viewCount']??0);
}

// map handle -> article id
$blogId='gid://shopify/Blog/120400806188';
$idOf=[]; $cur=null;
do{
  $r=$c->query(['query'=>'query($id:ID!,$cursor:String){ blog(id:$id){ articles(first:100,after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ id handle } } } }',
    'variables'=>['id'=>$blogId,'cursor'=>$cur]])->getDecodedBody();
  foreach($r['data']['blog']['articles']['nodes'] as $n) $idOf[$n['handle']]=$n['id'];
  $pi=$r['data']['blog']['articles']['pageInfo']; $cur=$pi['hasNextPage']?$pi['endCursor']:null;
}while($cur);

const M='mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{ field message } } }';
$batch=[]; $n=0;
arsort($views);
foreach($views as $vid=>$vc){
  $h=$handleOf[$vid]??null; $aid=$h?($idOf[$h]??null):null;
  printf("  %-45s %8d views  %s\n",$h??$vid,$vc,$aid?'':'(no article!)');
  if(!$aid) continue;
  $batch[]=['ownerId'=>$aid,'namespace'=>'custom','key'=>'view_count','type'=>'number_integer','value'=>(string)$vc];
  $n++;
}
if($apply && $batch){
  foreach(array_chunk($batch,25) as $b){
    $r=$c->query(['query'=>M,'variables'=>['mf'=>$b]])->getDecodedBody();
    $ue=$r['data']['metafieldsSet']['userErrors']??[];
    if(!empty($ue)||isset($r['errors'])) fwrite(STDERR,"  errs: ".json_encode(!empty($ue)?$ue:$r['errors'])."\n");
    usleep(350000);
  }
}
echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (--apply) ===\n");
echo "view_count set on $n articles\n";
