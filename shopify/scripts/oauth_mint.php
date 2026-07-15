<?php
declare(strict_types=1);
/**
 * One-shot OAuth token minter for the seo-audit Dev Dashboard app.
 * Re-mints ADMIN_API_TOKEN with the scopes currently configured on the app.
 *
 *   php oauth_mint.php url                       # 1) print the authorize URL
 *   php oauth_mint.php exchange "<code|redirURL>" # 2) swap code -> token, write .env
 *
 * Secrets (CLIENT_ID/CLIENT_SECRET) are read from .env, never printed.
 */
require __DIR__ . '/../../lib/bootstrap.php';

$shop   = $_ENV['SHOP_DOMAIN'];
$cid    = $_ENV['CLIENT_ID']     ?? '';
$secret = $_ENV['CLIENT_SECRET'] ?? '';
$redir  = 'https://localhost/callback';
$scopes = 'write_files,read_products,write_products,read_publications,read_content,write_content';
$envPath = realpath(__DIR__ . '/../../.env');
$stateFile = sys_get_temp_dir().'/asr_oauth_state';

$mode = $argv[1] ?? '';

if ($cid==='' ) { fwrite(STDERR,"CLIENT_ID missing from .env\n"); exit(1); }

if ($mode==='url') {
    $state = bin2hex(random_bytes(8));
    file_put_contents($stateFile,$state);
    $u = "https://$shop/admin/oauth/authorize?".http_build_query([
        'client_id'=>$cid,'scope'=>$scopes,'redirect_uri'=>$redir,'state'=>$state,
    ]);
    echo "\n1) Open this URL in a browser where you're logged into the store admin:\n\n$u\n\n";
    echo "2) Click 'Install'/'Update' to approve the new scopes.\n";
    echo "3) Your browser will try to load https://localhost/callback?... and FAIL to connect.\n";
    echo "   That's expected. COPY THE WHOLE URL from the address bar and run:\n\n";
    echo "   php oauth_mint.php exchange \"<paste the full localhost URL here>\"\n\n";
    exit(0);
}

if ($mode==='exchange') {
    if ($secret==='') { fwrite(STDERR,"CLIENT_SECRET missing from .env\n"); exit(1); }
    $arg = $argv[2] ?? '';
    if ($arg==='') { fwrite(STDERR,"Pass the code or full redirect URL.\n"); exit(1); }
    // accept full URL or bare code
    $code = $arg;
    if (str_contains($arg,'code=')) {
        $qs = parse_url($arg, PHP_URL_QUERY) ?: $arg;
        parse_str($qs,$p);
        $code = $p['code'] ?? '';
        if (isset($p['state']) && is_file($stateFile)) {
            $want=trim(file_get_contents($stateFile));
            if (!hash_equals($want,$p['state'])) { fwrite(STDERR,"State mismatch — re-run 'url' step.\n"); exit(1); }
        }
        if (isset($p['shop']) && $p['shop']!==$shop) { fwrite(STDERR,"Shop mismatch: {$p['shop']}\n"); exit(1); }
    }
    if ($code==='') { fwrite(STDERR,"No code found in input.\n"); exit(1); }

    $ch=curl_init("https://$shop/admin/oauth/access_token");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$cid,'client_secret'=>$secret,'code'=>$code])]);
    $body=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $d=json_decode($body,true);
    if ($http!==200 || empty($d['access_token'])) {
        fwrite(STDERR,"Exchange failed (HTTP $http): $body\n"); exit(1);
    }
    $tok=$d['access_token'];
    // write/replace ADMIN_API_TOKEN in .env
    $env=file_get_contents($envPath);
    if (preg_match('/^ADMIN_API_TOKEN=.*$/m',$env)) {
        $env=preg_replace('/^ADMIN_API_TOKEN=.*$/m','ADMIN_API_TOKEN='.$tok,$env);
    } else {
        $env.="\nADMIN_API_TOKEN=$tok\n";
    }
    file_put_contents($envPath,$env);
    echo "SUCCESS — new token written to .env\n";
    echo "  fingerprint: ".substr($tok,0,9)."…".substr($tok,-4)."\n";
    echo "  granted scopes: ".($d['scope']??'(not returned)')."\n";
    exit(0);
}

fwrite(STDERR,"Usage: php oauth_mint.php url   |   php oauth_mint.php exchange \"<code|url>\"\n");
exit(1);
