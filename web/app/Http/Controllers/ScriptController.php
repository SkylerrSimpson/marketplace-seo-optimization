<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketplaceCredential;
use App\Models\ScriptRun;
use App\Scripts\ReferenceFile;
use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptRegistry;
use App\Scripts\ScriptType;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScriptController extends Controller
{
    public function index(Request $request, ScriptRegistry $registry): View
    {
        $marketplace = $request->query('marketplace');
        $typeFilter = ScriptType::tryFrom((string) $request->query('type'));

        $scripts = $marketplace ? $registry->forMarketplace((string) $marketplace) : $registry->all();
        if ($typeFilter !== null) {
            $scripts = $scripts->filter(fn (ScriptDefinition $d) => $d->type === $typeFilter);
        }

        // Nest marketplace -> category -> {pipeline, standalone}. A step-less
        // script is standalone (not part of a fixed order); splitting them avoids
        // implying a false sequence. Within pipeline, scripts are grouped BY step:
        // two sharing a step number are alternate paths ("choose one", e.g. Fill
        // vs. Author Aspects) rendered under one shared step badge, while groupBy()
        // preserves the step-ascending order sortBy() established. See index.blade.php.
        $byMarketplace = $scripts
            ->groupBy(fn (ScriptDefinition $d) => $d->marketplace)
            ->sortKeys()
            ->map(fn ($forMarketplace) => $forMarketplace
                ->groupBy(fn (ScriptDefinition $d) => $d->category)
                ->sortKeys()
                ->map(fn ($forCategory) => [
                    'pipeline' => $forCategory
                        ->filter(fn (ScriptDefinition $d) => $d->step !== null)
                        ->sortBy(fn (ScriptDefinition $d) => [$d->step, $d->title])
                        ->values()
                        ->groupBy(fn (ScriptDefinition $d) => $d->step)
                        ->map(fn ($scriptsAtStep, $step) => [
                            'step' => (int) $step,
                            'scripts' => $scriptsAtStep->values(),
                        ])
                        ->values(),
                    'standalone' => $forCategory
                        ->filter(fn (ScriptDefinition $d) => $d->step === null)
                        ->sortBy(fn (ScriptDefinition $d) => $d->title)
                        ->values(),
                ])
            );

        return view('scripts.index', [
            'byMarketplace' => $byMarketplace,
            'activeMarketplace' => $marketplace,
            'activeType' => $typeFilter,
        ]);
    }

    public function show(string $slug, ScriptRegistry $registry): View
    {
        // find() throws OutOfBoundsException for an unknown slug — mapped to a 404
        // globally in bootstrap/app.php.
        $definition = $registry->find($slug);

        // Write-type scripts never get their live/confirm fields rendered here —
        // this keeps every run submitted from this form a dry-run/verify by
        // construction. The real live write only happens through the separate
        // RunConfirmationController flow, reached from a succeeded verify run's
        // own show page.
        $excludedParams = $definition->type === ScriptType::Write ? ['live', 'confirm'] : [];

        // This user's own latest run of this script — runs are private per user,
        // so the live panel and "promote to live" state reflect only their own
        // activity, never a teammate's.
        $latestRun = ScriptRun::where('script_slug', $slug)
            ->where('user_id', auth()->id())
            ->latest()->first();

        return view('scripts.show', [
            'definition' => $definition,
            'formParams' => collect($definition->params)->reject(
                fn ($param) => in_array($param->name, $excludedParams, true)
            ),
            'isWriteScript' => $definition->type === ScriptType::Write,
            // Only render the connection widget for a marketplace that has a
            // registered connection-check script (eBay, not Shopify) — avoids a
            // wasted fetch and a "Pinging..." flash on pages that can't be pinged.
            'hasConnectionCheck' => $registry->connectionCheckFor($definition->marketplace) !== null,
            // Accounts with no credentials yet — the page shows an advisory banner
            // pointing to the credentials screen instead of failing auth mid-run.
            // Covers Shopify too, which has no connection widget to surface this.
            'accountsMissingCredentials' => $this->accountsMissingCredentials($definition),
            'referenceFiles' => $this->resolveReferenceFiles($definition),
            'latestRun' => $latestRun,
            'latestRunCanPromoteToLive' => $latestRun?->isEligibleForLiveConfirmation($definition) ?? false,
            // Resume button label: only scripts with a real --resume param get
            // "Resume"; everything else gets "Run again" (an identical re-run).
            'hasResumeParam' => collect($definition->params)->contains(fn ($p) => $p->name === 'resume'),
        ]);
    }

    public function downloadReference(string $slug, string $index, ?string $account, ScriptRegistry $registry)
    {
        // Route params always arrive as strings; this file's strict_types=1 means
        // an int-typed parameter here would TypeError on the numeric-string route
        // segment rather than silently coercing — cast explicitly instead.
        $index = (int) $index;

        $definition = $registry->find($slug);
        abort_unless(isset($definition->referenceFiles[$index]), 404);
        $ref = $definition->referenceFiles[$index];

        $path = $ref->path;
        if ($ref->needsAccount()) {
            // The one place a URL-supplied string could otherwise reach a
            // filesystem path (str_replace into $path below) — validated against
            // this marketplace's configured account roster before it's ever used.
            abort_unless(in_array($account, $this->knownAccounts($definition->marketplace), true), 404);
            $path = str_replace('{account}', $account, $path);
        }

        $fullPath = config('paths.repo_root').'/'.$path;
        abort_unless(is_file($fullPath), 404);

        return response()->download($fullPath, basename($path));
    }

    /**
     * Which accounts for this script's marketplace currently have NO credentials
     * stored. Returns [] for a marketplace with no credential fields configured at
     * all (nothing to warn about). Accounts come from the script's own 'account'
     * param options, or ['default'] for a single-store marketplace (Shopify).
     *
     * @return list<string>
     */
    private function accountsMissingCredentials(ScriptDefinition $definition): array
    {
        $fields = config("credentials.{$definition->marketplace}.fields", []);
        if ($fields === []) {
            return [];
        }

        $accountParam = collect($definition->params)->firstWhere('name', 'account');
        $accounts = $accountParam?->options ?: ['default'];

        $missing = [];
        foreach ($accounts as $account) {
            $credential = MarketplaceCredential::forUser(auth()->id())
                ->forAccount($definition->marketplace, $account)->first();
            $set = array_filter($credential?->credentials ?? [], static fn (mixed $v): bool => filled($v));
            if ($set === []) {
                $missing[] = $account;
            }
        }

        return $missing;
    }

    /**
     * @return list<array{label: string, variants: list<array{account: ?string, exists: bool, columns: ?list<string>, downloadUrl: string}>}>
     */
    private function resolveReferenceFiles(ScriptDefinition $definition): array
    {
        $repoRoot = config('paths.repo_root');
        $slug = $definition->slug;
        $knownAccounts = $this->knownAccounts($definition->marketplace);

        return collect($definition->referenceFiles)->map(function (ReferenceFile $ref, int $index) use ($repoRoot, $slug, $knownAccounts) {
            $accounts = $ref->needsAccount() ? $knownAccounts : [null];

            $variants = array_map(function (?string $account) use ($ref, $index, $repoRoot, $slug) {
                $resolvedPath = $account !== null ? str_replace('{account}', $account, $ref->path) : $ref->path;
                $fullPath = $repoRoot.'/'.$resolvedPath;
                $exists = is_file($fullPath);

                return [
                    'account' => $account,
                    'exists' => $exists,
                    'columns' => $exists ? $this->csvColumns($fullPath) : null,
                    // array_filter's default truthy-check would strip index 0 (the
                    // first reference file) since 0 is falsy in PHP — only $account
                    // is meant to be optionally omitted here.
                    'downloadUrl' => route('scripts.reference.download', array_filter([
                        'slug' => $slug,
                        'index' => $index,
                        'account' => $account,
                    ], fn (mixed $v): bool => $v !== null)),
                ];
            }, $accounts);

            return ['label' => $ref->label, 'variants' => $variants];
        })->values()->all();
    }

    /** @return list<string>|null */
    private function csvColumns(string $fullPath): ?array
    {
        if (! str_ends_with($fullPath, '.csv')) {
            return null;
        }

        $handle = fopen($fullPath, 'r');
        if ($handle === false) {
            return null;
        }
        $firstLine = fgets($handle);
        fclose($handle);

        return $firstLine === false ? null : str_getcsv(trim($firstLine));
    }

    /**
     * The account roster a marketplace's scripts actually run against, from
     * config/credentials.php — the single source of truth also used by the
     * credentials UI, backups, and the dashboard. eBay is ['dows', 'ige'];
     * a single-store marketplace (Shopify) is ['default']; an unconfigured
     * one is [] (no {account} reference files to resolve).
     *
     * @return list<string>
     */
    private function knownAccounts(string $marketplace): array
    {
        return array_values(config("credentials.{$marketplace}.accounts", []));
    }
}
