<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketplaceCredential;
use App\Scripts\CredentialEnvMapper;
use App\Scripts\ScriptRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class ConnectionCheckController extends Controller
{
    /**
     * Page-load ping for the script-page connection widget. Deliberately NOT
     * ScriptRun/RunScriptJob/the queue — this is an ephemeral check, not an
     * auditable run, and going through the full job path would spam run
     * history on every script-page visit. Cached 60s per user+marketplace so
     * rapid navigation between script pages doesn't re-mint tokens and hit
     * eBay on every single request.
     */
    public function show(string $marketplace, ScriptRegistry $registry, CredentialEnvMapper $envMapper): JsonResponse
    {
        $definition = $registry->connectionCheckFor($marketplace);
        abort_unless($definition !== null, 404);

        $userId = (int) auth()->id();

        // Per user: the check runs with THIS user's own stored credentials, the
        // same ones a real run would inject — so the widget reflects what their
        // runs will actually use, and the cache is never shared across users.
        $cacheKey = "connection-check:{$marketplace}:{$userId}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json(['accounts' => $cached]);
        }

        $invoked = Process::path(config('paths.repo_root'))
            ->env($this->credentialEnv($marketplace, $userId, $envMapper))
            ->timeout(30)
            ->run([$definition->interpreter, $definition->cliPath, '--all', '--json']);

        $decoded = json_decode($invoked->output(), true);

        // Distinguish two very different failures:
        //  - An infra-level failure (process errored, timed out, or produced
        //    unparseable/empty output) means we couldn't check at all. Return an
        //    error the widget shows as "couldn't reach eBay" and, crucially, do
        //    NOT cache it — a transient blip must clear on the next load, not
        //    stick for a full minute (the old Cache::remember bug).
        //  - A per-account auth failure surfaces as {"ok": false} inside a valid
        //    result. That IS the check succeeding, so it's cached and rendered as
        //    a red dot like any other real answer.
        if ($invoked->failed() || ! is_array($decoded) || $decoded === []) {
            return response()->json(['error' => 'unreachable']);
        }

        Cache::put($cacheKey, $decoded, 60);

        return response()->json(['accounts' => $decoded]);
    }

    /**
     * The env vars this user's stored credentials map to, across every account for
     * the marketplace — same mapping a real run uses (CredentialEnvMapper), so the
     * check tests the user's own tokens rather than whatever the repo .env holds.
     *
     * @return array<string, string>
     */
    private function credentialEnv(string $marketplace, int $userId, CredentialEnvMapper $envMapper): array
    {
        $env = [];
        foreach (config("credentials.{$marketplace}.accounts", []) as $account) {
            $credential = MarketplaceCredential::forUser($userId)->forAccount($marketplace, $account)->first();
            if ($credential !== null) {
                $env = array_merge($env, $envMapper->envFor($marketplace, $account, $credential->credentials ?? []));
            }
        }

        return $env;
    }
}
