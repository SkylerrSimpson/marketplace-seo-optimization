@php
    $isTerminal = !$latestRun || $latestRun->status->isTerminal();
@endphp

{{-- Reactive state (runId, status, stdout, stderr, isTerminal, progressPercent,
canPromoteToLive) and the cancelRun()/resumeRun() actions live on the parent
grid's x-data in scripts/show.blade.php, shared with the run form so
submitting/cancelling/resuming all stay on this page instead of navigating to
/runs/{id} — this partial only renders/reacts, it never owns the state
itself. Every dynamic bit also gets a real server-rendered initial value (not
just an x-bind/x-text placeholder) so first paint, no-JS, and tests all see
correct content without needing Alpine to run first — x-show/x-text/x-bind
take over live updates once Alpine initializes. --}}
<div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">{{ __('Output') }}</h3>

    <p class="text-sm text-gray-500 dark:text-gray-400" x-show="runId === null" @if ($latestRun) style="display:none" @endif>
        {{ __('No runs yet. Configure the form and click Run to see live output here.') }}
    </p>

    <div
        x-show="runId !== null"
        @unless ($latestRun) style="display:none" @endunless
        data-run-id="{{ $latestRun->id ?? '' }}"
        x-bind:data-run-id="runId"
        data-terminal="{{ $isTerminal ? '1' : '0' }}"
        x-bind:data-terminal="isTerminal ? '1' : '0'"
    >
        <div class="flex items-center gap-2 flex-wrap text-xs mb-3">
            <span @class([
                'px-2 py-0.5 rounded font-medium',
                'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => $latestRun && in_array($latestRun->status->value, ['pending', 'running'], true),
                'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $latestRun?->status->value === 'succeeded',
                'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' => $latestRun?->status->value === 'failed',
                'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $latestRun?->status->value === 'cancelled',
            ])
                x-bind:class="{
                    'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200': status === 'pending' || status === 'running',
                    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200': status === 'succeeded',
                    'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200': status === 'failed',
                    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300': status === 'cancelled',
                }"
                x-text="status"
            >{{ $latestRun->status->value ?? '' }}</span>
            <span class="text-gray-400 dark:text-gray-500" x-show="exitCode !== null" @if (($latestRun->exit_code ?? null) === null) style="display:none" @endif>
                {{ __('exit code') }} <span x-text="exitCode">{{ $latestRun->exit_code ?? '' }}</span>
            </span>
            <span class="text-gray-400 dark:text-gray-500">
                {{ __('run #') }}<span x-text="runId">{{ $latestRun->id ?? '' }}</span>
            </span>

            <button type="button" @click="cancelRun()" x-show="!isTerminal"
                x-bind:disabled="cancelling"
                x-bind:class="{ 'opacity-60 cursor-not-allowed': cancelling }"
                @if ($isTerminal) style="display:none" @endif
                class="ml-auto px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300 hover:bg-red-100 dark:hover:bg-red-900">
                <span x-text="cancelling ? '{{ __('Cancelling…') }}' : '{{ __('Cancel') }}'">{{ __('Cancel') }}</span>
            </button>
            <button type="button" @click="resumeRun()"
                x-show="isTerminal && (status === 'failed' || status === 'cancelled')"
                @unless ($isTerminal && in_array($latestRun?->status->value, ['failed', 'cancelled'], true)) style="display:none" @endunless
                class="ml-auto px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900">
                {{ $hasResumeParam ? __('Resume') : __('Run again') }}
            </button>
        </div>

        {{-- Terminal-chrome header strip, purely visual — cues "this is a
        terminal" the way a window titlebar does. --}}
        <div class="rounded-t-md bg-gray-800 dark:bg-gray-950 px-3 py-2 flex items-center gap-1.5">
            <span class="w-2.5 h-2.5 rounded-full bg-gray-600"></span>
            <span class="w-2.5 h-2.5 rounded-full bg-gray-600"></span>
            <span class="w-2.5 h-2.5 rounded-full bg-gray-600"></span>
            <span class="ml-2 text-xs text-gray-400 font-mono truncate">{{ $definition->cliPath }}</span>
        </div>
        {{-- min-h so a fresh/short run still reads as a terminal, not a sliver;
        max-h so a long run scrolls within the viewport instead of pushing the
        page on forever — but nothing in between is padded with empty space. --}}
        <pre
            x-ref="terminal"
            class="min-h-[8rem] max-h-[32rem] overflow-y-auto rounded-b-md bg-gray-950 text-gray-200 text-xs font-mono p-4 whitespace-pre-wrap break-words"
        ><span x-text="stdout">{{ $latestRun->stdout ?? '' }}</span><span class="text-red-400" x-show="stderr !== ''" x-text="stderr" @if (! ($latestRun->stderr ?? '')) style="display:none" @endif>{{ $latestRun->stderr ?? '' }}</span></pre>

        <div class="mt-3 text-xs">
            @if ($latestRunCanPromoteToLive)
                <a href="{{ route('runs.confirm.create', $latestRun) }}"
                   x-bind:href="`/runs/${runId}/confirm`"
                   x-show="isTerminal && canPromoteToLive"
                   class="text-indigo-600 dark:text-indigo-400 font-medium hover:underline">
                    {{ __('Ready to promote to live →') }}
                </a>
            @else
                <a href="{{ $latestRun ? route('runs.show', $latestRun) : '#' }}"
                   x-bind:href="`/runs/${runId}`"
                   x-show="isTerminal && !canPromoteToLive"
                   class="text-indigo-600 dark:text-indigo-400 hover:underline"
                   @unless ($isTerminal) style="display:none" @endunless>
                    {{ __('View full run details →') }}
                </a>
            @endif
        </div>
    </div>
</div>
