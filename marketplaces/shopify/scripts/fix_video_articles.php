<?php
declare(strict_types=1);
/**
 * Fix the 20 video watch pages:
 *   1) back-date publishDate to the real YouTube upload date (was showing today)
 *   2) swap featured image to high-res maxresdefault.jpg (hqdefault looked blurry on the hero)
 *
 * IDEMPOTENT-ish: safe to re-run. DRY-RUN unless --apply.
 * Needs write_content.
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:'x',apiSecretKey:'x',scopes:['write_content'],hostName:$_ENV['SHOP_DOMAIN'],
  sessionStorage:new FileSessionStorage('/tmp/php_sessions'),apiVersion:$_ENV['API_VERSION']??'2026-04',isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

$dir=__DIR__.'/../data/drafts/';
$authored=json_decode(file_get_contents($dir.'video_watch_pages_authored.json'),true);
$metaList=json_decode(file_get_contents($dir.'video_metadata.json'),true);
$meta=[]; foreach($metaList as $m) $meta[$m['youtube_id']]=$m;

// map handle -> article id (Videos blog)
$blogId='gid://shopify/Blog/120400806188';
$byHandle=[]; $cur=null;
do{
  $r=$c->query(['query'=>'query($id:ID!,$cursor:String){ blog(id:$id){ articles(first:100,after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ id handle } } } }',
    'variables'=>['id'=>$blogId,'cursor'=>$cur]])->getDecodedBody();
  foreach($r['data']['blog']['articles']['nodes'] as $n) $byHandle[$n['handle']]=$n['id'];
  $pi=$r['data']['blog']['articles']['pageInfo'];
  $cur=$pi['hasNextPage']?$pi['endCursor']:null;
}while($cur);

const U='mutation($id:ID!,$article:ArticleUpdateInput!){ articleUpdate(id:$id, article:$article){ article{ id } userErrors{ field message } } }';
$fixed=0;$err=0;
foreach($authored as $a){
  $h=$a['handle']; $id=$byHandle[$h]??null;
  if(!$id){ echo "  MISS: $h\n"; continue; }
  $m=$meta[$a['youtube_id']]??[];
  $pubDate=($m['upload_date']??'').'T12:00:00Z';        // noon UTC on the real upload day
  $maxres='https://i.ytimg.com/vi/'.$a['youtube_id'].'/maxresdefault.jpg';
  $article=['publishDate'=>$pubDate,'image'=>['url'=>$maxres,'altText'=>$a['title']]];
  echo sprintf("  %-45s date=%s  img=maxres\n",$h,$m['upload_date']??'?');
  if($apply){
    $r=$c->query(['query'=>U,'variables'=>['id'=>$id,'article'=>$article]])->getDecodedBody();
    $ue=$r['data']['articleUpdate']['userErrors']??[];
    if(!empty($ue)||isset($r['errors'])){ fwrite(STDERR,"  !! $h: ".json_encode(!empty($ue)?$ue:$r['errors'])."\n");$err++;continue; }
    $fixed++; usleep(350000);
  }
}
echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (--apply) ===\n");
echo "fixed: $fixed  errors: $err\n";
