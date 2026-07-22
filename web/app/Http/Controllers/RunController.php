<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ScriptRunRequest;
use App\Jobs\RunScriptJob;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Scripts\ParamType;
use App\Scripts\ScriptRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RunController extends Controller
{
    public function store(ScriptRunRequest $request, string $slug, ScriptRegistry $registry): RedirectResponse|JsonResponse
    {
        $definition = $registry->find($slug);
        $params = $request->validated();

        // File-type params arrive as UploadedFile objects — ScriptRun.params is a
        // JSON column, so they must become stored path strings before the run row
        // is ever created. RunScriptJob's argv builder then treats that path exactly
        // like any other string param value.
        foreach ($definition->params as $param) {
            if ($param->type === ParamType::File && isset($params[$param->name])) {
                $storedPath = $params[$param->name]->store('script-uploads/'.Str::uuid(), 'local');
                $params[$param->name] = Storage::disk('local')->path($storedPath);
            }
        }

        $run = ScriptRun::create([
            'script_slug' => $slug,
            'user_id' => $request->user()->id,
            'params' => $params,
            'status' => ScriptRunStatus::Pending,
        ]);

        RunScriptJob::dispatch($run);

        // The script page's live-terminal panel submits this form itself (via
        // fetch, Accept: application/json) so it can stay on the same page and
        // start tracking the new run in place — see scripts/show.blade.php.
        // A plain/no-JS submission (no Accept: application/json) still gets the
        // original redirect-to-the-run's-own-page behavior untouched.
        if ($request->wantsJson()) {
            return response()->json(['run_id' => $run->id], HttpResponse::HTTP_CREATED);
        }

        return redirect()->route('runs.show', $run);
    }

    public function index(Request $request, ScriptRegistry $registry): View
    {
        // Runs are private per user — this list only ever shows the signed-in
        // user's own runs.
        $query = ScriptRun::query()->where('user_id', $request->user()->id)->with('user')->latest();

        // Status filter — only an exact known enum value is honored.
        $activeStatus = $request->query('status');
        if (in_array($activeStatus, array_map(fn (ScriptRunStatus $s) => $s->value, ScriptRunStatus::cases()), true)) {
            $query->where('status', $activeStatus);
        } else {
            $activeStatus = null;
        }

        // Marketplace filter — resolved to that marketplace's slugs via the registry
        // (the run row only stores a slug, not a marketplace).
        $marketplaces = $registry->all()->pluck('marketplace')->unique()->sort()->values();
        $activeMarketplace = $request->query('marketplace');
        if ($activeMarketplace !== null && $marketplaces->contains($activeMarketplace)) {
            $query->whereIn('script_slug', $registry->forMarketplace($activeMarketplace)->map->slug->all());
        } else {
            $activeMarketplace = null;
        }

        return view('runs.index', [
            'runs' => $query->paginate(25)->withQueryString(),
            'registry' => $registry,
            'statuses' => ScriptRunStatus::cases(),
            'marketplaces' => $marketplaces,
            'activeStatus' => $activeStatus,
            'activeMarketplace' => $activeMarketplace,
        ]);
    }

    public function show(ScriptRun $run, ScriptRegistry $registry): View
    {
        $this->abortUnlessOwnedOrAdmin($run->user_id);
        $definition = $registry->find($run->script_slug);

        return view('runs.show', [
            'run' => $run,
            'definition' => $definition,
            // Promotion is owner-only (an admin may VIEW another user's run but not
            // act on it), so don't offer the button on someone else's run — it would
            // only 404 at the confirm step.
            'canPromoteToLive' => $run->user_id === auth()->id() && $run->isEligibleForLiveConfirmation($definition),
            'downloadableFiles' => collect(Storage::disk('local')->files($run->storageDirectory()))
                ->map(fn (string $path) => basename($path))
                ->values(),
        ]);
    }

    /**
     * Lightweight JSON poll target for the live terminal panel on a script's
     * own page (and, in principle, anywhere else that wants to watch a run
     * without a full page reload) — pure DB read, no process/job involved.
     */
    public function output(ScriptRun $run): JsonResponse
    {
        $this->abortUnlessOwnedOrAdmin($run->user_id);

        return response()->json([
            'status' => $run->status->value,
            'stdout' => $run->stdout ?? '',
            'stderr' => $run->stderr ?? '',
            'exit_code' => $run->exit_code,
            'is_terminal' => $run->status->isTerminal(),
            'progress_percent' => $run->progressPercent(),
        ]);
    }

    /**
     * Stops a run in progress. Only ever called via fetch from the script
     * page (Accept: application/json) — there's no non-JS path to this
     * control, unlike the run form, so no redirect fallback is needed.
     *
     * IMPORTANT for write-type scripts: this does NOT undo anything already
     * written to eBay before the signal lands — these scripts write one
     * item at a time via ReviseItem, and SIGTERM stops the *next* item, not
     * ones already sent. The confirmation prompt that says this lives in
     * scripts/show.blade.php's cancelRun(), not here — this endpoint trusts
     * the browser already confirmed with the user.
     */
    public function cancel(ScriptRun $run): JsonResponse
    {
        $this->abortUnlessOwned($run->user_id);

        // Can't cancel what's already done — matches the 404-on-terminal-
        // state gating style RunConfirmationController already uses.
        abort_if($run->status->isTerminal(), 404);

        // Set BEFORE signaling: RunScriptJob (running in a different PHP
        // process — the queue worker) trusts this DB flag, not the raw
        // exit code, to tell a cancelled run apart from a genuinely failed
        // one. Also closes the race where cancel arrives while the job is
        // still between queue-pickup and actually spawning the process —
        // RunScriptJob checks this flag before it ever starts the child.
        $run->update(['cancel_requested_at' => now()]);

        if ($run->pid !== null) {
            $this->signalProcess($run->pid, SIGTERM);
        }

        return response()->json(['cancelled' => true]);
    }

    /**
     * Re-submits a Failed or Cancelled run's params as a new run. If the script
     * has a `resume` bool param (e.g. audit_shipping_policy.php's --resume) it's
     * forced true so the script can skip what it already finished; otherwise it's
     * just an identical re-run (the UI labels it "Run again" vs. "Resume" to match).
     */
    public function resume(ScriptRun $run, Request $request, ScriptRegistry $registry): JsonResponse
    {
        $this->abortUnlessOwned($run->user_id);
        abort_unless(in_array($run->status, [ScriptRunStatus::Failed, ScriptRunStatus::Cancelled], true), 404);

        $definition = $registry->find($run->script_slug);
        $params = $run->params;

        if (collect($definition->params)->contains(fn ($p) => $p->name === 'resume')) {
            $params['resume'] = true;
        }

        $newRun = ScriptRun::create([
            'script_slug' => $run->script_slug,
            'user_id' => $request->user()->id,
            'params' => $params,
            'status' => ScriptRunStatus::Pending,
        ]);

        RunScriptJob::dispatch($newRun);

        return response()->json(['run_id' => $newRun->id], HttpResponse::HTTP_CREATED);
    }

    /**
     * Best-effort signal by PID — the only way to reach the queue worker's
     * process from a web request. If it already exited, that's fine: RunScriptJob
     * resolves final status from cancel_requested_at, not from whether the signal
     * landed.
     */
    private function signalProcess(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);

            return;
        }

        exec('kill -'.$signal.' '.escapeshellarg((string) $pid).' 2>/dev/null');
    }

    public function download(ScriptRun $run, string $filename)
    {
        $this->abortUnlessOwnedOrAdmin($run->user_id);

        // basename() strips any '../' traversal attempt — the only path this can
        // ever resolve to is a file inside this run's own storage directory.
        $safeName = basename($filename);
        $path = $run->storageDirectory().'/'.$safeName;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $safeName);
    }
}
