<?php
declare(strict_types=1);
/**
 * Build the "Videos" blog hub + one watch-page article per video.
 *
 * Each article gets:
 *   - a LIVE responsive YouTube <iframe> (present in the HTML on load = watch page)
 *   - the authored context paragraph
 *   - featured image = the video's YouTube thumbnail (for the hub listing)
 *   - metafields custom.youtube_id / video_upload_date / video_duration / video_description
 *     (the theme snippet video-jsonld.liquid reads these to emit VideoObject schema)
 *
 * Prerequisites: data/drafts/video_watch_pages_authored.json (hand-authored
 * context paragraph + meta title/description per video) and
 * data/drafts/video_metadata.json (from pull_youtube_meta.php) must exist.
 *
 * IDEMPOTENT: reuses the Videos blog if it exists; skips an article whose handle
 * already exists in that blog. DRY-RUN unless --apply.
 *   php marketplaces/shopify/scripts/build_video_watch_pages.php
 *   php marketplaces/shopify/scripts/build_video_watch_pages.php --apply
 *
 * Needs write_content.
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:'x', apiSecretKey:'x', scopes:['write_content'],
  hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'),
  apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

$dir = __DIR__ . '/../data/drafts/';
$authored = json_decode(file_get_contents($dir.'video_watch_pages_authored.json'), true);
$metaList = json_decode(file_get_contents($dir.'video_metadata.json'), true);
$meta=[]; foreach($metaList as $m) $meta[$m['youtube_id']]=$m;

function gql($c,$q,$vars=[]){
  $payload=['query'=>$q];
  if($vars) $payload['variables']=$vars;
  $r=$c->query($payload)->getDecodedBody();
  if(isset($r['errors'])){ fwrite(STDERR,"GQL ERR ".json_encode($r['errors'])."\n"); exit(1); }
  return $r['data'];
}

// ---- 1. find or create the Videos blog ----
$BLOG_HANDLE='videos';
$d=gql($c,'{ blogs(first:100){ nodes{ id handle title } } }');
$blogId=null;
foreach($d['blogs']['nodes'] as $b) if($b['handle']===$BLOG_HANDLE){ $blogId=$b['id']; break; }

if(!$blogId){
  if($apply){
    $r=gql($c,'mutation($blog:BlogCreateInput!){ blogCreate(blog:$blog){ blog{ id handle } userErrors{ field message } } }',
      ['blog'=>['title'=>'Videos','handle'=>$BLOG_HANDLE]]);
    $ue=$r['blogCreate']['userErrors']??[];
    if($ue){ fwrite(STDERR,"blogCreate errs: ".json_encode($ue)."\n"); exit(1); }
    $blogId=$r['blogCreate']['blog']['id'];
    echo "CREATED blog 'Videos' -> $blogId\n";
  } else {
    echo "[dry-run] would CREATE blog 'Videos' (handle: $BLOG_HANDLE)\n";
    $blogId='gid://shopify/Blog/DRYRUN';
  }
} else {
  echo "blog 'Videos' exists -> $blogId\n";
}

// ---- existing article handles in the blog (idempotency) ----
$existing=[];
if(strpos($blogId,'DRYRUN')===false){
  $cur=null;
  do{
    $d=gql($c,'query($id:ID!,$cursor:String){ blog(id:$id){ articles(first:100, after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ handle } } } }',
      ['id'=>$blogId,'cursor'=>$cur]);
    foreach($d['blog']['articles']['nodes'] as $n) $existing[$n['handle']]=true;
    $pi=$d['blog']['articles']['pageInfo'];
    $cur=$pi['hasNextPage']?$pi['endCursor']:null;
  }while($cur);
}

// ---- 2. create one article per video ----
const AC='mutation($article:ArticleCreateInput!){ articleCreate(article:$article){ article{ id handle } userErrors{ field message } } }';

$pf=fopen($dir.'video_build_preview.csv','w');
fputcsv($pf,['action','handle','title','youtube_id','upload_date','duration','featured_image']);
$created=0;$skipped=0;$err=0;
$now=gmdate('c');

foreach($authored as $a){
  $id=$a['youtube_id']; $m=$meta[$id]??[];
  $handle=$a['handle'];
  if(isset($existing[$handle])){ echo "  SKIP (exists): $handle\n"; $skipped++; fputcsv($pf,['skip',$handle,$a['title'],$id,'','','']); continue; }

  $embed='https://www.youtube.com/embed/'.$id;
  $thumb='https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
  $safeTitle=htmlspecialchars($a['title'],ENT_QUOTES);
  $body =
    '<div class="video-watch-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin-bottom:1.5rem;">'
    .'<iframe style="position:absolute;top:0;left:0;width:100%;height:100%;" src="'.$embed.'" '
    .'title="'.$safeTitle.'" frameborder="0" '
    .'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
    .'allowfullscreen></iframe></div>'
    .'<p>'.htmlspecialchars($a['body'],ENT_QUOTES).'</p>';

  $metafields=[
    ['namespace'=>'custom','key'=>'youtube_id','type'=>'single_line_text_field','value'=>$id],
    ['namespace'=>'custom','key'=>'video_upload_date','type'=>'single_line_text_field','value'=>(string)($m['upload_date']??'')],
    ['namespace'=>'custom','key'=>'video_duration','type'=>'single_line_text_field','value'=>(string)($m['duration']??'')],
    ['namespace'=>'custom','key'=>'video_description','type'=>'multi_line_text_field','value'=>$a['body']],
  ];

  $article=[
    'blogId'=>$blogId,
    'title'=>$a['title'],
    'handle'=>$handle,
    'body'=>$body,
    'summary'=>$a['meta_description'],
    'author'=>['name'=>'ASR Outdoor'],
    'isPublished'=>true,
    'image'=>['url'=>$thumb,'altText'=>$a['title']],
    'metafields'=>$metafields,
  ];

  fputcsv($pf,[$apply?'create':'dry-create',$handle,$a['title'],$id,$m['upload_date']??'',$m['duration']??'',$thumb]);

  if($apply){
    $r=gql($c,AC,['article'=>$article]);
    $ue=$r['articleCreate']['userErrors']??[];
    if($ue){ fwrite(STDERR,"  !! $handle: ".json_encode($ue)."\n"); $err++; continue; }
    // meta title/description via global.* metafields (SEO tab) — separate from summary
    $aid=$r['articleCreate']['article']['id'];
    gql($c,'mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{ field message } } }',
      ['mf'=>[
        ['ownerId'=>$aid,'namespace'=>'global','key'=>'title_tag','type'=>'single_line_text_field','value'=>$a['meta_title']],
        ['ownerId'=>$aid,'namespace'=>'global','key'=>'description_tag','type'=>'single_line_text_field','value'=>$a['meta_description']],
      ]]);
    echo "  CREATED: $handle\n"; $created++; usleep(350000);
  } else {
    echo "  [dry-run] would create: $handle\n"; $created++;
  }
}
fclose($pf);

echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (--apply to write) ===\n");
echo "blog: Videos ($BLOG_HANDLE)\n";
echo "articles created : $created\n";
echo "articles skipped : $skipped\n";
echo "errors           : $err\n";
echo "CSV: shopify/data/drafts/video_build_preview.csv\n";
