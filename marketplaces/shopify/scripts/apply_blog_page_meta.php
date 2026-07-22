<?php
declare(strict_types=1);
/**
 * Set SEO meta title/description for blog ARTICLES and PAGES from an authored JSON.
 * Stored as global.title_tag / global.description_tag metafields (single_line_text).
 * These two metafields are independent, so setting one never clobbers the other.
 *
 * IDEMPOTENT: skips a field whose live value already matches. DRY-RUN unless --apply.
 *   php apply_blog_page_meta.php
 *   php apply_blog_page_meta.php --apply
 *
 * Needs write_content (articles/pages metafields).
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:'x', apiSecretKey:'x', scopes:['write_content'],
  hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'),
  apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

$authored=json_decode(file_get_contents(__DIR__.'/../data/drafts/blog_page_meta_authored.json'),true);

function fetchAll($c,$type){ // type: 'articles' | 'pages'
  $map=[];$cur=null;
  do{
    $q='query($cursor:String){ '.$type.'(first:50, after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ id handle tt:metafield(namespace:"global",key:"title_tag"){value} dd:metafield(namespace:"global",key:"description_tag"){value} } } }';
    $r=$c->query(['query'=>$q,'variables'=>['cursor'=>$cur]])->getDecodedBody();
    if(isset($r['errors'])){ fwrite(STDERR,"$type ERR ".json_encode($r['errors'])."\n"); exit(1); }
    $d=$r['data'][$type];
    foreach($d['nodes'] as $n) $map[$n['handle']]=['id'=>$n['id'],'tt'=>(string)($n['tt']['value']??''),'dd'=>(string)($n['dd']['value']??'')];
    $cur=$d['pageInfo']['hasNextPage']?$d['pageInfo']['endCursor']:null;
  }while($cur);
  return $map;
}
$maps=['articles'=>fetchAll($c,'articles'),'pages'=>fetchAll($c,'pages')];

const M='mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{field message} } }';
$pf=fopen(__DIR__.'/../data/drafts/blog_page_meta_preview.csv','w');
fputcsv($pf,['type','handle','field','length','flag','value']);

$batch=[];$setT=0;$setD=0;$skip=0;$miss=0;$warn=0;
function flushBatch(&$batch,$c,$apply){
  if(!$batch) return [0,[]];
  if(!$apply){ $n=count($batch);$batch=[];return [$n,[]]; }
  $r=$c->query(['query'=>M,'variables'=>['mf'=>$batch]])->getDecodedBody();
  $ue=$r['data']['metafieldsSet']['userErrors']??[];
  if(isset($r['errors'])) $ue[]=['message'=>json_encode($r['errors'])];
  $n=count($batch);$batch=[];usleep(300000);return [$n,$ue];
}

foreach(['articles','pages'] as $type){
  foreach($authored[$type] as $handle=>$fields){
    if(!isset($maps[$type][$handle])){ echo "  MISS ($type): $handle\n"; $miss++; continue; }
    $owner=$maps[$type][$handle];
    foreach([['mt','title_tag','tt',60],['md','description_tag','dd',165]] as [$k,$key,$cur,$lim]){
      if(!isset($fields[$k])) continue;
      $val=trim($fields[$k]); $len=mb_strlen($val);
      $flag=$len>$lim?"OVER_$lim":'';
      if($flag) $warn++;
      // idempotent: skip if already equal
      if($owner[$cur]===$val){ $skip++; continue; }
      fputcsv($pf,[$type,$handle,$key,$len,$flag,$val]);
      $batch[]=['ownerId'=>$owner['id'],'namespace'=>'global','key'=>$key,'type'=>'single_line_text_field','value'=>$val];
      if($k==='mt')$setT++; else $setD++;
      if(count($batch)>=25){ [$n,$ue]=flushBatch($batch,$c,$apply); if($ue) fwrite(STDERR,"  errs: ".json_encode($ue)."\n"); }
    }
  }
}
[$n,$ue]=flushBatch($batch,$c,$apply); if($ue) fwrite(STDERR,"  errs: ".json_encode($ue)."\n");
fclose($pf);

echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (--apply to write) ===\n");
echo "meta TITLES to set : $setT\n";
echo "meta DESCS to set  : $setD\n";
echo "already correct (skipped): $skip\n";
echo "handles not found  : $miss\n";
echo "length warnings    : $warn\n";
echo "CSV: shopify/data/drafts/blog_page_meta_preview.csv\n";
