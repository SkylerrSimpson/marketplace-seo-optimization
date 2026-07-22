<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketplaceCredential;
use App\Models\ScriptRun;
use App\Scripts\BackupChecker;
use App\Scripts\ScriptRegistry;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(ScriptRegistry $registry, BackupChecker $backupChecker): View
    {
        return view('dashboard', [
            // The signed-in user's own runs and credentials only — both are private
            // per user (see MarketplaceCredential::scopeForUser and ScriptRun's
            // per-user scoping in RunController).
            'recentRuns' => ScriptRun::query()->where('user_id', auth()->id())
                ->with('user')->latest()->limit(10)->get(),
            'registry' => $registry,
            'credentialSummary' => MarketplaceCredential::forUser(auth()->id())
                ->orderBy('marketplace')->orderBy('account')->get()
                ->map(fn (MarketplaceCredential $c) => $c->summaryRow()),
            // Surfaces the same gate RunConfirmationController::authorizeEligible()
            // enforces, up front — so a missing backup shows here instead of only
            // being discovered as a 403 on the confirm page.
            'backupStatus' => collect(config('credentials.ebay.accounts', []))->map(fn (string $account) => [
                'account' => $account,
                'hasBackup' => $backupChecker->hasBackupFor('ebay', $account),
            ]),
            // Marketplaces with a registered connection-check script — the dashboard
            // widget pings each (cached 60s server-side) so overall connection
            // health is visible up front, not only buried on individual script pages.
            'connectionMarketplaces' => $registry->all()->pluck('marketplace')->unique()
                ->filter(fn (string $m) => $registry->connectionCheckFor($m) !== null)
                ->sort()->values(),
        ]);
    }
}
