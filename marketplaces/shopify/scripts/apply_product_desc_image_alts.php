<?php
declare(strict_types=1);
/**
 * Fill missing/empty alt on <img> tags inside product descriptionHtml.
 * Most are the Amazon-imported "Shopify_Prop65graphic" warning image.
 *
 * IDEMPOTENT: only touches <img> with no alt / alt="". DRY-RUN unless --apply.
 *   php marketplaces/shopify/scripts/apply_product_desc_image_alts.php          # dry run + CSV preview
 *   php marketplaces/shopify/scripts/apply_product_desc_image_alts.php --apply  # write descriptionHtml back
 *
 * Needs write_products (productUpdate descriptionHtml — single field, no clobber).
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:'x', apiSecretKey:'x', scopes:['write_products'],
  hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'),
  apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

const Q='query($cursor:String){ products(first:50, after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ id title handle descriptionHtml } } }';
const M='mutation($id:ID!,$html:String!){ productUpdate(product:{id:$id,descriptionHtml:$html}){ userErrors{field message} } }';

function gen_alt(string $src, string $title): string {
  $fn=preg_replace('/\.[a-z0-9]+$/i','',preg_replace('#^.*/#','',preg_replace('/\?.*$/','',$src)));
  if(stripos($fn,'prop65')!==false || stripos($fn,'prop_65')!==false) return 'California Proposition 65 warning';
  if(stripos($fn,'hqdefault')!==false || stripos($fn,'thumbnail')!==false) return $title.' product video';
  // generic: clean filename, fallback to product title
  $fn=preg_replace('/[_-]?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i','',$fn);
  $fn=preg_replace('/\._AC.*$/i','',$fn);
  $toks=array_filter(preg_split('/[\s_\-]+/',$fn), fn($t)=>$t!=='' && !preg_match('/^\d{4,}$/',$t) && !preg_match('/^[0-9a-f]{6,}$/i',$t));
  $alt=trim(preg_replace('/\s+/',' ',implode(' ',$toks)));
  return (strlen($alt)>=4) ? ucfirst($alt) : $title;
}

$products=[]; $cursor=null;
do{
  $r=$c->query(['query'=>Q,'variables'=>['cursor'=>$cursor]])->getDecodedBody();
  if(isset($r['errors'])){ fwrite(STDERR,"LIST ERR ".json_encode($r['errors'])."\n"); exit(1); }
  $d=$r['data']['products'];
  foreach($d['nodes'] as $n) $products[]=$n;
  $cursor=$d['pageInfo']['hasNextPage']?$d['pageInfo']['endCursor']:null;
}while($cursor);

$pf=fopen(__DIR__.'/../data/drafts/product_desc_alt_preview.csv','w');
fputcsv($pf,['product_handle','product_title','image_filename','proposed_alt']);
$tot=0;$prodChanged=0;$err=0;
foreach($products as $p){
  $html=(string)$p['descriptionHtml']; if($html===''||stripos($html,'<img')===false) continue;
  $changed=0;$rows=[];
  $new=preg_replace_callback('/<img\b[^>]*>/i', function($m) use (&$changed,&$rows,$p){
    $tag=$m[0];
    if(preg_match('/\balt\s*=\s*"([^"]*)"/i',$tag,$am)&&trim($am[1])!=='') return $tag;
    if(preg_match("/\balt\s*=\s*'([^']*)'/i",$tag,$a2)&&trim($a2[1])!=='') return $tag;
    if(!preg_match('/\bsrc\s*=\s*"([^"]*)"/i',$tag,$sm)) return $tag;
    $alt=gen_alt($sm[1],$p['title']); $e=htmlspecialchars($alt,ENT_QUOTES,'UTF-8');
    $rows[]=[$p['handle'],$p['title'],basename(explode('?',$sm[1])[0]),$alt]; $changed++;
    if(preg_match('/\balt\s*=\s*"[^"]*"/i',$tag)) return preg_replace('/\balt\s*=\s*"[^"]*"/i','alt="'.$e.'"',$tag,1);
    if(preg_match("/\balt\s*=\s*'[^']*'/i",$tag)) return preg_replace("/\balt\s*=\s*'[^']*'/i",'alt="'.$e.'"',$tag,1);
    return preg_replace('/<img\b/i','<img alt="'.$e.'"',$tag,1);
  }, $html);
  if($changed>0){
    $tot+=$changed;$prodChanged++; foreach($rows as $r) fputcsv($pf,$r);
    if($apply){
      $w=$c->query(['query'=>M,'variables'=>['id'=>$p['id'],'html'=>$new]])->getDecodedBody();
      $ue=$w['data']['productUpdate']['userErrors']??[];
      if(!empty($ue)||isset($w['errors'])){ fwrite(STDERR,"  !! {$p['handle']}: ".json_encode(!empty($ue)?$ue:$w['errors'])."\n");$err++; }
      usleep(300000);
    }
  }
}
fclose($pf);
echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (--apply to write) ===\n");
echo "products scanned : ".count($products)."\n";
echo "products changed : $prodChanged\n";
echo "desc imgs alt-filled: $tot  (errors $err)\n";
echo "CSV: shopify/data/drafts/product_desc_alt_preview.csv\n";
