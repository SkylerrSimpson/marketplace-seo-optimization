<?php
declare(strict_types=1);
/**
 * Fill missing/empty alt text on <img> tags inside blog article bodies.
 * Generates alt from the (descriptive) image filename; falls back to the
 * article title when the filename is a code/ASIN/UUID/camera name.
 *
 * IDEMPOTENT: only touches <img> with no alt or alt="". Images that already
 * have alt text are left untouched, so re-running is safe.
 *
 *   php apply_blog_image_alts.php            # DRY RUN — writes a preview file
 *   php apply_blog_image_alts.php --apply    # write changes back to Shopify
 *
 * Needs a token with write_content (the seo-content re-mint). DRY-RUN default.
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:'x', apiSecretKey:'x', scopes:['write_content'],
  hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'),
  apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

const Q_LIST='query($cursor:String){ articles(first:50, after:$cursor){ pageInfo{hasNextPage endCursor} nodes{ id handle title body image{ altText url } } } }';
const M_UPDATE='mutation($id:ID!,$article:ArticleUpdateInput!){ articleUpdate(id:$id, article:$article){ article{ id } userErrors{ field message } } }';

// ---- alt-text generation -------------------------------------------------
$DROP = array_flip(['asro','asr','blog','body','images','image','landscape','shopify',
  'post','hero','cover','premium','content','desktop','mobile','update','updated','main',
  'parent','scale','final','photos','photo','the','a','an','of','listing','product','detail',
  'lifestyle','px','copy','new','draft','wip','version','v','collage',
  'blk','grn','brn','khk','wht','gettyimages','stockcake','istock','shutterstock']);

function clean_title(string $t): string {
  // strip emoji + trailing brand/marketing tails, collapse
  $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u','',$t);
  $t = preg_replace('/\s*[|\-–—:]\s*ASR.*/i','',$t);
  $t = trim(preg_replace('/\s+/',' ',$t));
  return $t;
}

function looks_like_code(string $fn): bool {
  return (bool)(
    preg_match('/^[A-Z0-9]{9,12}$/',$fn) ||            // amazon ASIN-ish (718X5w4QClL)
    preg_match('/^IMG[_-]?\d+/i',$fn) ||
    preg_match('/^PXL[_-]?\d+/i',$fn) ||
    preg_match('/^\d{6,}/',$fn) ||                      // 20250302_121039
    preg_match('/^[0-9A-F]{3}[0-9A-F]{4,}$/i',$fn) ||   // 387A0997
    preg_match('/^(screenshot|document|untitled)/i',$fn)
  );
}

function alt_from_src(string $src, string $title): array {
  // returns [alt, source] where source = 'filename' | 'title'
  $fn = preg_replace('/\?.*$/','',$src);          // drop ?v=...
  $fn = preg_replace('#^.*/#','',$fn);            // basename
  $fn = preg_replace('/\.[a-z0-9]+$/i','',$fn);   // drop extension
  $orig = $fn;
  // Amazon product photos (._AC_SL1500…) are always ASIN-named -> use title
  if (preg_match('/\._AC[_.]/i',$fn) || preg_match('/^[A-Za-z0-9]{10,11}\./',$fn)) {
    return [clean_title($title),'title'];
  }
  // strip UUIDs, amazon/size suffixes (anchored so we don't eat real words)
  $fn = preg_replace('/[_-]?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i','',$fn);
  $fn = preg_replace('/\._AC.*$/i','',$fn);       // literal "._AC…" only
  $fn = preg_replace('/_SL\d+/i','',$fn);
  $fn = preg_replace('/_\d{2,4}x\d{2,4}/i','',$fn);
  $fn = preg_replace('/\b\d{2,4}x\d{2,4}\b/i','',$fn);
  if (looks_like_code($orig)) return [clean_title($title),'title'];
  $toks = preg_split('/[\s_\-]+/',$fn);
  global $DROP; $keep=[];
  foreach($toks as $t){
    $t=trim($t); if($t==='') continue;
    $lt=strtolower($t);
    if(isset($DROP[$lt])) continue;
    if(preg_match('/^\d{5,}$/',$t)) continue;          // long number runs (timestamps/ids)
    if(preg_match('/^\d$/',$t)) continue;              // single-digit dedup counters
    if(preg_match('/^[0-9a-f]{6,}$/i',$t) && !preg_match('/[g-z]/i',$t)) continue; // hex blobs
    $keep[]=$t;
  }
  // single alphanumeric token with both letters+digits = leftover ASIN/code
  if(count($keep)===1 && preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9]{8,13}$/',$keep[0])
     && !preg_match('/\d(pc|l|oz|lb|in|ft|mm|cm)$/i',$keep[0])){
    return [clean_title($title),'title'];
  }
  $alt=trim(preg_replace('/\s+/',' ',implode(' ',$keep)));
  if($alt==='' || str_word_count($alt) < 1 || strlen($alt) < 3){
    return [clean_title($title),'title'];
  }
  // sentence-case: upper first char, keep common acronyms upper
  $alt = ucfirst($alt);
  $alt = preg_replace_callback('/\b(usa|edc|led|abs|pe|stem|uv|ss)\b/i', fn($m)=>strtoupper($m[1]), $alt);
  return [$alt,'filename'];
}
// -------------------------------------------------------------------------

$articles=[]; $cursor=null;
do{
  $r=$c->query(['query'=>Q_LIST,'variables'=>['cursor'=>$cursor]])->getDecodedBody();
  if(isset($r['errors'])){ fwrite(STDERR,"LIST ERR ".json_encode($r['errors'])."\n"); exit(1); }
  $d=$r['data']['articles'];
  foreach($d['nodes'] as $n) $articles[]=$n;
  $cursor=$d['pageInfo']['hasNextPage']?$d['pageInfo']['endCursor']:null;
}while($cursor);

$previewPath=__DIR__.'/../data/drafts/blog_alt_preview.csv';
$pf=fopen($previewPath,'w');
fputcsv($pf,['article_handle','article_title','image_filename','alt_source','proposed_alt','needs_review','article_url']);
$totImgs=0;$totFix=0;$featFix=0;$totArtChanged=0;$flagUSA=[];$titleFallback=[];

foreach($articles as $a){
  $body=(string)$a['body'];
  $changed=0; $rows=[];
  $new = $body==='' ? $body : preg_replace_callback('/<img\b[^>]*>/i', function($m) use (&$changed,&$rows,$a,&$flagUSA,&$titleFallback){
    $tag=$m[0];
    // has non-empty alt? leave alone
    if(preg_match('/\balt\s*=\s*"([^"]*)"/i',$tag,$am) && trim($am[1])!=='') return $tag;
    if(preg_match("/\balt\s*=\s*'([^']*)'/i",$tag,$am2) && trim($am2[1])!=='') return $tag;
    // need src
    if(!preg_match('/\bsrc\s*=\s*"([^"]*)"/i',$tag,$sm)){ return $tag; }
    $src=$sm[1];
    [$alt,$srcKind]=alt_from_src($src,$a['title']);
    $altEsc=htmlspecialchars($alt,ENT_QUOTES,'UTF-8');
    $fn=preg_replace('/\?.*$/','',preg_replace('#^.*/#','',$src));
    $usa=(stripos($alt,'usa')!==false||stripos($alt,'america')!==false);
    $rows[]=[$a['handle'],$a['title'],$fn,$srcKind,$alt,$usa?'USA-CLAIM':'',
      'https://asroutdoor.com/blogs/journal/'.$a['handle']];
    if($usa) $flagUSA[]="{$a['handle']}: $fn -> \"$alt\"";
    if($srcKind==='title') $titleFallback[]="{$a['handle']}: $fn -> \"$alt\"";
    $changed++;
    // inject or replace alt
    if(preg_match('/\balt\s*=\s*"[^"]*"/i',$tag)) return preg_replace('/\balt\s*=\s*"[^"]*"/i','alt="'.$altEsc.'"',$tag,1);
    if(preg_match("/\balt\s*=\s*'[^']*'/i",$tag)) return preg_replace("/\balt\s*=\s*'[^']*'/i",'alt="'.$altEsc.'"',$tag,1);
    return preg_replace('/<img\b/i','<img alt="'.$altEsc.'"',$tag,1);
  }, $body);

  $totImgs+=substr_count($body,'<img');

  // featured / cover image (separate field article.image.altText)
  $featAlt=null;
  $img=$a['image']??null;
  if($img && !empty($img['url']) && trim((string)($img['altText']??''))===''){
    $featAlt=clean_title($a['title']);
    $ffn=preg_replace('/\?.*$/','',preg_replace('#^.*/#','',$img['url']));
    $fusa=(stripos($featAlt,'usa')!==false||stripos($featAlt,'america')!==false);
    $rows[]=[$a['handle'],$a['title'],$ffn,'featured',$featAlt,$fusa?'USA-CLAIM':'',
      'https://asroutdoor.com/blogs/journal/'.$a['handle']];
    if($fusa) $flagUSA[]="{$a['handle']}: $ffn -> \"$featAlt\" (featured)";
  }

  if($changed>0 || $featAlt!==null){
    $totFix+=$changed; if($featAlt!==null) $featFix++;
    $totArtChanged++;
    foreach($rows as $r) fputcsv($pf,$r);
    if($apply){
      $inp=[];
      if($changed>0) $inp['body']=$new;
      if($featAlt!==null) $inp['image']=['altText'=>$featAlt];
      $w=$c->query(['query'=>M_UPDATE,'variables'=>['id'=>$a['id'],'article'=>$inp]])->getDecodedBody();
      $ue=$w['data']['articleUpdate']['userErrors']??[];
      if(!empty($ue)||isset($w['errors'])){ fwrite(STDERR,"  !! FAILED {$a['handle']}: ".json_encode(!empty($ue)?$ue:$w['errors'])."\n"); }
      usleep(350000);
    }
  }
}
fclose($pf);

echo ($apply?"=== APPLIED ===\n":"=== DRY RUN (no writes; pass --apply) ===\n");
echo "articles scanned : ".count($articles)."\n";
echo "articles changed : $totArtChanged\n";
echo "BODY images alt-filled: $totFix\n";
echo "  - from filename : ".($totFix-count($titleFallback))."\n";
echo "  - title fallback: ".count($titleFallback)."\n";
echo "FEATURED images alt-filled: $featFix\n";
echo "preview written  : shopify/data/drafts/blog_alt_preview.csv\n";
if($flagUSA){
  echo "\n⚠ ".count($flagUSA)." alt(s) mention USA/America (verify claim accuracy):\n";
  foreach($flagUSA as $x) echo "    $x\n";
}
