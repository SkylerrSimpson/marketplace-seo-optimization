<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ScheduledRun;
use App\Scripts\ScriptRegistry;
use App\Scripts\ScriptType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ScheduledRunController extends Controller
{
    private const FREQUENCIES = ['hourly', 'daily', 'weekly'];

    public function index(ScriptRegistry $registry): View
    {
        return view('scheduled.index', [
            // Schedules are private per user — a user only sees and manages their own.
            'scheduledRuns' => ScheduledRun::where('user_id', auth()->id())->with('user')->latest()->get(),
            'registry' => $registry,
            // Only read-type scripts are schedulable — a scheduled run must never
            // be able to write live (see DispatchScheduledRuns for why).
            'schedulableScripts' => $registry->all()
                ->filter(fn ($d) => $d->type === ScriptType::Read)
                ->sortBy('title')
                ->values(),
            'frequencies' => self::FREQUENCIES,
        ]);
    }

    public function store(Request $request, ScriptRegistry $registry): RedirectResponse
    {
        $readSlugs = $registry->all()
            ->filter(fn ($d) => $d->type === ScriptType::Read)
            ->map->slug->values()->all();

        $validated = $request->validate([
            'script_slug' => ['required', 'string', Rule::in($readSlugs)],
            'account' => ['nullable', 'string'],
            'frequency' => ['required', 'string', Rule::in(self::FREQUENCIES)],
            'hour' => ['required_unless:frequency,hourly', 'nullable', 'integer', 'between:0,23'],
        ]);

        // Validate the account against the chosen script's own account param, if any.
        $definition = $registry->find($validated['script_slug']);
        $accountParam = collect($definition->params)->firstWhere('name', 'account');
        $params = [];
        if ($accountParam !== null) {
            $account = $validated['account'] ?? null;
            abort_unless(in_array($account, $accountParam->options ?? [], true), 422, 'Invalid account for this script.');
            $params['account'] = $account;
        }

        ScheduledRun::create([
            'script_slug' => $validated['script_slug'],
            'params' => $params,
            'cron' => $this->cronFor($validated['frequency'], (int) ($validated['hour'] ?? 0)),
            'enabled' => true,
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('scheduled.index')->with('status', 'schedule-created');
    }

    public function toggle(ScheduledRun $scheduledRun): RedirectResponse
    {
        $this->abortUnlessOwned($scheduledRun->user_id);
        $scheduledRun->update(['enabled' => ! $scheduledRun->enabled]);

        return redirect()->route('scheduled.index')->with('status', 'schedule-updated');
    }

    public function destroy(ScheduledRun $scheduledRun): RedirectResponse
    {
        $this->abortUnlessOwned($scheduledRun->user_id);
        $scheduledRun->delete();

        return redirect()->route('scheduled.index')->with('status', 'schedule-deleted');
    }

    private function cronFor(string $frequency, int $hour): string
    {
        return match ($frequency) {
            'hourly' => '0 * * * *',
            'daily' => "0 {$hour} * * *",
            'weekly' => "0 {$hour} * * 1", // Mondays
            default => '0 0 * * *',
        };
    }
}
