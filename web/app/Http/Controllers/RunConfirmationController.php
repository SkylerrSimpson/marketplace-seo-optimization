<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmRunRequest;
use App\Jobs\RunScriptJob;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Scripts\BackupChecker;
use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptRegistry;
use App\Scripts\WriteConfirmationMode;
use App\Scripts\WriteConfirmationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RunConfirmationController extends Controller
{
    public function create(ScriptRun $run, ScriptRegistry $registry, WriteConfirmationResolver $resolver, BackupChecker $backupChecker): View|RedirectResponse
    {
        $definition = $this->authorizeEligible($run, $registry);

        if ($redirect = $this->backupGateRedirect($run, $definition, $backupChecker)) {
            return $redirect;
        }

        $mode = $resolver->resolve($run->params);

        return view('runs.confirm', [
            'run' => $run,
            'definition' => $registry->find($run->script_slug),
            'mode' => $mode,
            'itemId' => $mode === WriteConfirmationMode::Single ? $resolver->singleItemId($run->params) : null,
        ]);
    }

    public function store(
        ConfirmRunRequest $request,
        ScriptRun $run,
        ScriptRegistry $registry,
        WriteConfirmationResolver $resolver,
        BackupChecker $backupChecker,
    ): RedirectResponse {
        $definition = $this->authorizeEligible($run, $registry);

        if ($redirect = $this->backupGateRedirect($run, $definition, $backupChecker)) {
            return $redirect;
        }

        $mode = $resolver->resolve($run->params);
        $params = collect($run->params)->except('verify')->all();
        $params['live'] = true;
        if ($mode === WriteConfirmationMode::Bulk) {
            $params['confirm'] = 'WRITE';
        }

        $liveRun = ScriptRun::create([
            'script_slug' => $run->script_slug,
            'user_id' => $request->user()->id,
            'preview_run_id' => $run->id,
            'params' => $params,
            'status' => ScriptRunStatus::Pending,
            'confirmation_text' => $request->validated('confirmation'),
            'confirmed_at' => now(),
        ]);

        RunScriptJob::dispatch($liveRun);

        return redirect()->route('runs.show', $liveRun);
    }

    /**
     * The real "you shouldn't be here" guard — a run that isn't a completed,
     * verify-mode preview of a write script can never be promoted to live. This
     * stays a hard 403 because reaching it means a hand-crafted URL, not a normal
     * user action. Returns the resolved definition so callers don't re-find it.
     */
    private function authorizeEligible(ScriptRun $run, ScriptRegistry $registry): ScriptDefinition
    {
        // A user can only ever promote their OWN preview run to a live write —
        // 404 (not 403) so another user's run id can't even be probed for.
        $this->abortUnlessOwned($run->user_id);

        $definition = $registry->find($run->script_slug);

        abort_unless(
            $run->isEligibleForLiveConfirmation($definition),
            403,
            'This run is not eligible for live confirmation — it must be a completed, verify-mode preview of a write script.',
        );

        return $definition;
    }

    /**
     * The backup gate is a normal, expected state a real user hits ("I haven't
     * backed up yet"), not tampering — so instead of a dead-end 403 it sends them
     * straight to /backups with an actionable message and remembers the run to
     * return to. No live write happens on either path; the gate is preserved.
     */
    private function backupGateRedirect(ScriptRun $run, ScriptDefinition $definition, BackupChecker $backupChecker): ?RedirectResponse
    {
        $account = $run->params['account'] ?? null;

        if ($backupChecker->hasBackupFor($definition->marketplace, is_string($account) ? $account : null)) {
            return null;
        }

        return redirect()
            ->route('backups.index')
            ->with('backupGate', [
                'account' => is_string($account) ? $account : 'default',
                'runId' => $run->id,
            ]);
    }
}
