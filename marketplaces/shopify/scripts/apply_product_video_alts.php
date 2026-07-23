<?php
declare(strict_types=1);
/**
 * Fill missing alt on product VIDEO / EXTERNAL_VIDEO media (the hqdefault.jpg
 * poster thumbnails render with empty alt because the media alt is blank).
 *
 * IDEMPOTENT: only touches video media whose alt is empty. DRY-RUN unless --apply.
 *   php marketplaces/shopify/scripts/apply_product_video_alts.php
 *   php marketplaces/shopify/scripts/apply_product_video_alts.php --apply
 *
 * Needs write_products (productUpdateMedia).
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:'x', apiSecretKey:'x', scopes:['write_products'],
  hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'),
  apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

const Q='query($cursor:String){ products(first:50, after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ id title handle media(first:25){ nodes{ mediaContentType alt ... on Video{ id } ... on ExternalVideo{ id } } } } } }';
const M='mutation($pid:ID!,$media:[UpdateMediaInput!]!){ productUpdateMedia(productId:$pid, media:$media){ media{ alt } mediaUserErrors{ field message } } }';

$products=[]; $cursor=null;
do{
  $r=$c->query(['query'=>Q,'variables'=>['cursor'=>$cursor]])->getDecodedBody();
  if(isset($r['errors'])){ fwrite(STDERR,"LIST ERR ".json_encode($r['errors'])."\n"); exit(1); }
  $d=$r['data']['products'];
  foreach($d['nodes'] as $n) $products[]=$n;
  $cursor=$d['pageInfo']['hasNextPage']?$d['pageInfo']['endCursor']:null;
}while($cursor);

$pf=fopen(__DIR__.'/../data/drafts/product_video_alt_preview.csv','w');
fputcsv($pf,['product_handle','product_title','media_type','media_id','proposed_alt']);
$tot=0;$prodChanged=0;$err=0;
foreach($products as $p){
  $upd=[];
  foreach($p['media']['nodes'] as $mn){
    $type=$mn['mediaContentType'];
    if($type!=='VIDEO' && $type!=='EXTERNAL_VIDEO') continue;
    if(trim((string)($mn['alt']??''))!=='') continue;       // already has alt
    if(empty($mn['id'])) continue;
    $alt=$p['title'].' product video';
    $upd[]=['id'=>$mn['id'],'alt'=>$alt];
    fputcsv($pf,[$p['handle'],$p['title'],$type,$mn['id'],$alt]);
  }
  if($upd){
    $tot+=count($upd);$prodChanged++;
    if($apply){
      $w=$c->query(['query'=>M,'variables'=>['pid'=>$p['id'],'media'=>$upd]])->getDecodedBody();
      $ue=$w['data']['productUpdateMedia']['mediaUserErrors']??[];
      if(!empty($ue)||isset($w['errors'])){ fwrite(STDERR,"  !! {$p['handle']}: ".json_encode(!empty($ue)?$ue:$w['errors'])."\n");$err++; }
      usleep(300000);
    }
  }
}
fclose($pf);
echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (--apply to write) ===\n");
echo "products scanned   : ".count($products)."\n";
echo "products w/ videos fixed: $prodChanged\n";
echo "video media alt-filled  : $tot  (errors $err)\n";
echo "CSV: shopify/data/drafts/product_video_alt_preview.csv\n";
