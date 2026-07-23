<?php
declare(strict_types=1);
/**
 * Prepend a prose intro <p> to 10 thin product descriptions (spec-bullet-only,
 * no lead-in prose) so they read as actual copy, not just a bullet list.
 * Product IDs and intro text are hardcoded below, curated from a manual
 * thin-content audit — this script has no external input file.
 * Preserves the existing descriptionHtml verbatim (intro + original bullets).
 * Idempotent: skips if the intro is already present. DRY-RUN unless --apply.
 *
 * Usage: php marketplaces/shopify/scripts/apply_thin_descriptions.php [--apply]
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context; use Shopify\Auth\FileSessionStorage; use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
Context::initialize(apiKey:$_ENV['APP_API_KEY']??'custom-app', apiSecretKey:$_ENV['APP_API_SECRET']??'custom-app', scopes:['write_products'], hostName:$_ENV['SHOP_DOMAIN'], sessionStorage:new FileSessionStorage('/tmp/php_sessions'), apiVersion:$_ENV['API_VERSION']??'2026-04', isEmbeddedApp:false);
$c=new Graphql($_ENV['SHOP_DOMAIN'],$_ENV['ADMIN_API_TOKEN']);

$intros=[
 '9512980218156'=>"Clean your catch on the boat or at the dock with this 6-inch fishing fillet knife. The floating plastic handle will not sink if it goes overboard, the ergonomic grip keeps control wet or dry, and the included clip-on sheath makes it easy to carry and store.",
 '9512984052012'=>"Keep mealtime covered on the trail with this compact 7-function camping utensil. It combines a fork, spoon, knife, can opener, bottle opener, corkscrew, and tent reamer in one 4-inch tool that slips into the included belt-loop carrying case.",
 '9513008824620'=>"Carry your water bottle hands-free with this slip-on bottle holder and aluminum carabiner. The heavy-duty rubber loop grips a standard bottle, the durable nylon strap clips to a pack or belt, and the built-in key ring keeps your keys close on hikes and day trips.",
 '9513050702124'=>"Pack the essentials into one pocket-sized tool with this 7-in-1 survival multitool. It puts a compass, safety whistle, LED flashlight, signal mirror, 5x magnifier, thermometer, and a small storage compartment in one durable body, making it a great way to teach kids outdoor safety or round out any camping or emergency kit.",
 '9513105228076'=>"Add reliable light to any emergency kit with this high-visibility glow stick. Just snap and shake for instant light that glows for up to 12 hours, with no batteries or flame required. At 6 inches long and lightweight, it stores easily in a car, bug-out bag, boat, or camping pack.",
 '9513188688172'=>"Stay warm when it counts with this heavy-duty Mylar emergency blanket. The aluminized PE material reflects body heat and is tear resistant plus flame, wind, and water retardant, while the high-visibility orange helps rescuers spot you. It folds down small for a kit and opens to a full 83 by 51 inches.",
 '9516211142956'=>"Handle camp chores with this compact Buckshot camping hatchet. The black steel blade measures 4 inches on a 10-inch overall frame for controlled chopping and splitting, and it ships with a nylon sheath and a built-in cord cutter for kindling, rope, and trail tasks.",
 '9516216516908'=>"Do more with one tool using this 3-in-1 survival hatchet. Forged from a single piece of tough steel, it works as a hatchet, hammer, and pry bar in a 12.5-inch frame with a comfortable grip, making it a versatile choice for camping, vehicle kits, and emergency preparedness.",
 '9516251480364'=>"Keep a working edge on your gear with this grooved angle sharpening stone. The 180-grit aluminum oxide stone has an angled center groove sized for fish hooks and a flat face for knives and blades, plus a hole for a string, lanyard, or keychain so it rides along in a tackle box or pack.",
 '9516431376684'=>"Clip gear where you need it with this 7-inch jumbo aluminum carabiner. The oversized 6.5 by 4 inch frame and 4.5-inch cushion grip make it easy to attach water bottles, keys, and gear to a backpack for dog walking, hiking, camping, and everyday use. Available in 6 colors. Note: not rated for climbing.",
];

const READ='query($id:ID!){ product(id:$id){ title descriptionHtml } }';
const WRITE='mutation($id:ID!,$html:String!){ productUpdate(product:{id:$id,descriptionHtml:$html}){ userErrors{field message} } }';

echo $apply?"=== APPLY ===\n":"=== DRY RUN (--apply to write) ===\n";
$done=0;$skip=0;$err=0;
foreach($intros as $id=>$intro){
 $gid="gid://shopify/Product/$id";
 $d=$c->query(['query'=>READ,'variables'=>['id'=>$gid]])->getDecodedBody();
 $p=$d['data']['product']??null; if(!$p){echo "  MISS $id\n";$err++;continue;}
 $cur=(string)($p['descriptionHtml']??'');
 if(stripos(strip_tags($cur),substr($intro,0,40))!==false){echo "  SKIP (intro present): {$p['title']}\n";$skip++;continue;}
 $new="<p>".htmlspecialchars($intro,ENT_QUOTES,'UTF-8')."</p>\n".$cur;
 echo "  ".($apply?"SET":"WOULD SET")." {$p['title']}  (+".strlen($intro)."c)\n";
 if(!$apply){continue;}
 $w=$c->query(['query'=>WRITE,'variables'=>['id'=>$gid,'html'=>$new]])->getDecodedBody();
 $ue=$w['data']['productUpdate']['userErrors']??[];
 if(!empty($ue)||isset($w['errors'])){echo "    FAILED: ".json_encode(!empty($ue)?$ue:$w['errors'])."\n";$err++;continue;}
 $done++; usleep(300000);
}
echo "\n".($apply?"APPLIED":"DRY-RUN").": done $done, skipped $skip, errors $err\n";
