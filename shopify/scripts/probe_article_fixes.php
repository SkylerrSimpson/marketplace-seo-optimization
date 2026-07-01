<?php
declare(strict_types=1);
// Probe: can articleUpdate set publishedAt? And which thumbnail sizes exist per video.
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;
Context::initialize(apiKey:'x',apiSecretKey:'x',scopes:['read_content'],hostName:$_ENV['SHOP_DOMAIN'],
  sessionStorage:new FileSessionStorage('/tmp/php_sessions'),apiVersion:$_ENV['API_VERSION']??'2026-04',isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

// 1. introspect ArticleUpdateInput fields
$q='{ __type(name:"ArticleUpdateInput"){ inputFields{ name } } }';
$r=$c->query(['query'=>$q])->getDecodedBody();
$fields=array_column($r['data']['__type']['inputFields'],'name');
echo "ArticleUpdateInput has publishedAt: ".(in_array('publishedAt',$fields)?'YES':'NO')."\n";
echo "ArticleUpdateInput has publishDate: ".(in_array('publishDate',$fields)?'YES':'NO')."\n";
echo "fields: ".implode(', ',$fields)."\n\n";

// 2. which thumbnail exists per video
function thumbOK($url){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>10]);
  curl_exec($ch);
  $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  $len=curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD);
  curl_close($ch);
  return [$code,$len];
}
$meta=json_decode(file_get_contents(__DIR__.'/../data/drafts/video_metadata.json'),true);
foreach($meta as $m){
  $id=$m['youtube_id'];
  [$mc,$ml]=thumbOK("https://i.ytimg.com/vi/$id/maxresdefault.jpg");
  [$sc,$sl]=thumbOK("https://i.ytimg.com/vi/$id/sddefault.jpg");
  $best = ($mc==200 && $ml>1000) ? 'maxres' : (($sc==200 && $sl>1000) ? 'sd' : 'hq');
  printf("%-13s max:%s(%d) sd:%s(%d) -> BEST:%s\n",$id,$mc,$ml,$sc,$sl,$best);
}
