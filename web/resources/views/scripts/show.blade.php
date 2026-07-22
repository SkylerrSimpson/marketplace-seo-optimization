<x-app-layout>
    @push('head')
        <style>
            [x-cloak] { display: none !important; }
            .dowscripts-wifi-bars rect {
                animation: dowscripts-wifi-pulse 1.2s ease-in-out infinite;
                transform-origin: bottom;
            }
            @keyframes dowscripts-wifi-pulse {
                0%, 100% { opacity: 0.25; }
                50% { opacity: 1; }
            }
            @media (prefers-reduced-motion: reduce) {
                .dowscripts-wifi-bars rect { animation: none; opacity: 0.75; }
            }
        </style>
        <script>
            // Registered before Alpine scans the DOM so the Run button's
            // x-bind:disabled (in a separate x-data scope from the widget
            // that populates this) can always safely read $store.connection,
            // regardless of which component happens to initialize first.
            document.addEventListener('alpine:init', () => {
                Alpine.store('connection', { status: 'checking', accounts: {} });
            });
        </script>
    @endpush

    <x-slot name="header">
        <a href="{{ route('scripts.index', ['marketplace' => $definition->marketplace]) }}"
           class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 hover:underline mb-2">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 111.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd" />
            </svg>
            {{ __('Back to Scripts') }}
        </a>
        <x-breadcrumb :items="[
            ['label' => ucfirst($definition->marketplace), 'url' => route('scripts.index', ['marketplace' => $definition->marketplace])],
            ['label' => $definition->title, 'url' => null],
        ]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $definition->title }}
        </h2>
    </x-slot>

    @php
        // Server-rendered default for the account picker (if this script has one),
        // used to seed the Alpine selectedAccount so the Run-button connection gate
        // starts in sync with what's shown — see connectionBlocked().
        $accountParam = $formParams->firstWhere('name', 'account');
        $selectedAccountDefault = $accountParam ? old('account', $accountParam->default) : null;
        // A required picker with no explicit default renders its first <option>
        // as selected; mirror that here so the connection gate is scoped to the
        // right account from the first paint rather than falling back to "any".
        if ($accountParam && ($selectedAccountDefault === null || $selectedAccountDefault === '') && $accountParam->required) {
            $selectedAccountDefault = $accountParam->options[0] ?? null;
        }
    @endphp
    <div class="py-12">
        <div
            class="max-w-6xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 md:grid-cols-2 gap-6 items-start"
            x-data="{
                runId: {{ $latestRun?->id ?? 'null' }},
                status: @js($latestRun?->status->value ?? null),
                stdout: @js($latestRun?->stdout ?? ''),
                stderr: @js($latestRun?->stderr ?? ''),
                exitCode: @js($latestRun?->exit_code ?? null),
                isTerminal: {{ !$latestRun || $latestRun->status->isTerminal() ? 'true' : 'false' }},
                progressPercent: null,
                // True from the moment Cancel is confirmed until the poll loop
                // actually observes the job stop (isTerminal flips true) — without
                // this the button just sits there, unchanged, for up to one 2s poll
                // interval, which reads like the click never registered.
                cancelling: false,
                canPromoteToLive: {{ $latestRunCanPromoteToLive ? 'true' : 'false' }},
                // Whether this script writes live — only write scripts gate the
                // Run button on the connection check (see connectionBlocked()).
                isWriteScript: {{ $isWriteScript ? 'true' : 'false' }},
                // Mirrors the form's account picker so connectionBlocked() and the
                // missing-credentials banner can react to it. Initialized to the
                // server-rendered selection so x-model doesn't change what's shown.
                selectedAccount: @js($selectedAccountDefault ?? null),
                // Accounts with no credentials stored yet (server-computed). The
                // banner keys off whichever account is currently picked.
                accountsMissingCredentials: @js($accountsMissingCredentials),
                credentialsMissing() {
                    return this.accountsMissingCredentials.includes(this.selectedAccount ?? 'default');
                },
                timer: null,
                poll() {
                    if (this.runId === null) return;
                    const el = this.$refs.terminal;
                    const wasNearBottom = el ? (el.scrollTop + el.clientHeight >= el.scrollHeight - 40) : true;
                    fetch(`/runs/${this.runId}/output`)
                        .then((r) => r.json())
                        .then((data) => {
                            this.status = data.status;
                            this.exitCode = data.exit_code;
                            this.stdout = data.stdout;
                            this.stderr = data.stderr;
                            this.isTerminal = data.is_terminal;
                            this.progressPercent = data.progress_percent;
                            if (this.isTerminal) {
                                this.cancelling = false;
                            }
                            if (wasNearBottom) {
                                this.$nextTick(() => { if (el) el.scrollTop = el.scrollHeight; });
                            }
                            if (this.isTerminal && this.timer) {
                                clearInterval(this.timer);
                                this.timer = null;
                            }
                        });
                },
                startPolling() {
                    if (this.timer) clearInterval(this.timer);
                    this.timer = setInterval(() => this.poll(), 2000);
                },
                // Shared by a fresh submission and Resume — both just create a
                // new ScriptRun server-side and hand back {run_id}; from here
                // on watching it is identical either way.
                trackNewRun(runId) {
                    this.runId = runId;
                    this.status = 'pending';
                    this.stdout = '';
                    this.stderr = '';
                    this.exitCode = null;
                    this.isTerminal = false;
                    this.progressPercent = null;
                    this.cancelling = false;
                    // Eligibility for the live-write promotion CTA is decided
                    // server-side (ScriptRun::isEligibleForLiveConfirmation) —
                    // never re-derived here. A freshly-tracked run just links to
                    // its own full page once done; that page has the real
                    // button once eBay's verify actually confirms it.
                    this.canPromoteToLive = false;
                    this.startPolling();
                },
                submitRun(event) {
                    if (!this.isTerminal) return;
                    const form = event.target;
                    const formData = new FormData(form);
                    fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: formData,
                    }).then((r) => {
                        if (!r.ok) {
                            // Validation failed (or some other server error) — fall
                            // back to a real, non-AJAX submission so Laravel's normal
                            // redirect-with-errors/old-input flow still runs. Calling
                            // .submit() directly (not this handler) doesn't re-fire
                            // the 'submit' event, so this can't loop.
                            form.submit();
                            return null;
                        }
                        return r.json();
                    }).then((data) => {
                        if (data) this.trackNewRun(data.run_id);
                    });
                },
                resumeRun() {
                    if (!this.isTerminal || this.runId === null) return;
                    fetch(`/runs/${this.runId}/resume`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                    }).then((r) => r.json()).then((data) => this.trackNewRun(data.run_id));
                },
                cancelRun() {
                    if (this.isTerminal || this.runId === null || this.cancelling) return;
                    const message = {{ $isWriteScript ? 'true' : 'false' }}
                        ? 'This is a live-write script. Anything already written to eBay before you cancel will NOT be undone — only the remaining items will be stopped. Cancel anyway?'
                        : 'Cancel this run?';
                    if (!confirm(message)) return;
                    this.cancelling = true;
                    fetch(`/runs/${this.runId}/cancel`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                    }).catch(() => { this.cancelling = false; });
                    // Beyond that, no local status flip — the existing poll loop
                    // (still running, since we're not terminal yet) picks up
                    // status: 'cancelled' on its own once the job actually stops,
                    // which is also what clears `cancelling` back to false.
                },
                init() {
                    this.$nextTick(() => { if (this.$refs.terminal) this.$refs.terminal.scrollTop = this.$refs.terminal.scrollHeight; });
                    if (this.runId !== null && !this.isTerminal) {
                        this.startPolling();
                    }
                },
                // Reads the global $store.connection the widget above populates
                // (a separate x-data scope). Only ever gates a live-write
                // script — a read script is harmless and should always run,
                // surfacing its own error if credentials are bad rather than
                // being pre-emptively blocked. Scoped to the account currently
                // picked in the form, so a broken IGE credential never blocks a
                // DOWS run (and vice versa); falls back to blocking on any failed
                // account only for scripts with no account picker at all.
                connectionBlocked() {
                    if (!this.isWriteScript) return false;
                    const conn = this.$store.connection;
                    if (!conn || conn.status !== 'done') return false;
                    const account = this.selectedAccount;
                    if (account && Object.prototype.hasOwnProperty.call(conn.accounts, account)) {
                        return !conn.accounts[account].ok;
                    }
                    return Object.values(conn.accounts).some((a) => !a.ok);
                },
            }"
            x-init="init()"
        >

            {{-- Left column — unchanged from before the two-column layout. --}}
            <div class="space-y-6">

                {{-- Connection widget — own independent x-data scope for its
                own init/fetch, but writes into the global $store.connection
                (registered in @push('head') above) rather than local state,
                so the run-form's Run button (a different x-data scope
                entirely) can gate on it too. Fetches once on load. Only
                rendered when this marketplace has a registered connection-check
                script (ScriptRegistry::connectionCheckFor() non-null) — see
                ScriptController::show; a marketplace that can't be pinged
                (Shopify today) shows no widget at all rather than a flash. --}}
                @if ($hasConnectionCheck)
                <div
                    x-data="{
                        init() {
                            fetch('{{ url('/connection-check/'.$definition->marketplace) }}')
                                .then((r) => (r.ok ? r.json() : { error: 'unreachable' }))
                                .then((data) => {
                                    if (data && data.accounts) {
                                        Alpine.store('connection').accounts = data.accounts;
                                        Alpine.store('connection').status = 'done';
                                    } else {
                                        // Couldn't reach the marketplace to check at all —
                                        // show an explicit error, not a blank box.
                                        Alpine.store('connection').status = 'error';
                                    }
                                })
                                .catch(() => { Alpine.store('connection').status = 'error'; });
                        },
                    }"
                    x-init="init()"
                    x-cloak
                    class="p-4 bg-white dark:bg-gray-800 shadow sm:rounded-lg"
                >
                    <template x-if="$store.connection.status === 'checking'">
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <svg class="dowscripts-wifi-bars" width="18" height="14" viewBox="0 0 18 14" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <rect x="0" y="9" width="3" height="5" rx="1" fill="currentColor" style="animation-delay: 0ms" />
                                <rect x="5" y="5" width="3" height="9" rx="1" fill="currentColor" style="animation-delay: 150ms" />
                                <rect x="10" y="2" width="3" height="12" rx="1" fill="currentColor" style="animation-delay: 300ms" />
                            </svg>
                            <span>{{ __('Pinging...') }}</span>
                        </div>
                    </template>
                    <template x-if="$store.connection.status === 'done'">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <template x-for="(result, account) in $store.connection.accounts" :key="account">
                                {{-- The script's own detail string rides along as a title tooltip
                                     on a failed account, so "why" is one hover away. --}}
                                <span class="inline-flex items-center gap-1.5" :title="result.ok ? null : result.detail">
                                    <span class="w-2 h-2 rounded-full" :class="result.ok ? 'bg-green-500' : 'bg-red-500'"></span>
                                    <span class="font-medium text-gray-700 dark:text-gray-300" x-text="account"></span>
                                    <span :class="result.ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'" x-text="result.ok ? '{{ __('Connected') }}' : '{{ __('Connection issue') }}'"></span>
                                </span>
                            </template>
                        </div>
                    </template>
                    <template x-if="$store.connection.status === 'error'">
                        <div class="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
                            <svg class="w-4 h-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ __("Couldn't reach :marketplace to check the connection.", ['marketplace' => ucfirst($definition->marketplace)]) }}</span>
                        </div>
                    </template>
                </div>
                @endif

                {{-- Advisory (not a hard block — some scripts are local-only and
                     need no API credentials). Shows for whichever account is picked
                     when that account has nothing stored yet, linking straight to
                     its credentials screen. --}}
                <div x-cloak x-show="credentialsMissing()"
                    class="p-4 rounded-lg bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-900 text-sm text-amber-800 dark:text-amber-200">
                    <div class="flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p>{{ __('No credentials are set for') }} <span class="font-semibold" x-text="selectedAccount ?? 'default'"></span>. {{ __('This script will fail until they are added.') }}</p>
                            <a :href="'{{ url('/credentials') }}/{{ $definition->marketplace }}/' + (selectedAccount ?? 'default') + '/edit'"
                                class="mt-1 inline-block font-medium underline hover:no-underline">{{ __('Set credentials') }} &rarr;</a>
                        </div>
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg space-y-2">
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $definition->description }}</p>
                    @if ($definition->usageNotes)
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $definition->usageNotes }}</p>
                    @endif
                </div>

                @if (! empty($referenceFiles))
                    <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg space-y-4">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Reference files') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('The actual files this script reads from — open one to see what\'s already there before you run this.') }}</p>

                        @foreach ($referenceFiles as $ref)
                            <div class="border-t border-gray-100 dark:border-gray-700 pt-4 first:border-0 first:pt-0">
                                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $ref['label'] }}</p>

                                @foreach ($ref['variants'] as $variant)
                                    <div class="mt-2 flex items-center gap-2 flex-wrap">
                                        @if ($variant['exists'])
                                            <a href="{{ $variant['downloadUrl'] }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                                ⬇ {{ $variant['account'] ? __('Download for :account', ['account' => $variant['account']]) : __('Download') }}
                                            </a>
                                        @else
                                            <span class="text-sm text-gray-400 dark:text-gray-600">
                                                {{ $variant['account'] ? __('Not generated yet for :account', ['account' => $variant['account']]) : __('Not generated yet') }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($variant['columns'])
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 font-mono">
                                            {{ __('Columns:') }} {{ implode(', ', $variant['columns']) }}
                                        </p>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($isWriteScript)
                    <div class="p-4 bg-amber-50 dark:bg-amber-900 text-amber-800 dark:text-amber-200 rounded-lg text-sm">
                        {{ __('This is a Write-type script. Every run submitted from this page is a dry-run/verify only — there\'s no live/confirm field here. Once a verify run succeeds, its result page shows a "Promote to live" button that walks you through the real confirmation (retype the item ID, or type WRITE for a bulk run) before anything is written to eBay.') }}
                    </div>
                @endif

                @if (! empty($definition->creates))
                    <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg space-y-3">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('What this creates') }}</h3>
                        @foreach ($definition->creates as $created)
                            <div>
                                <p class="text-sm text-gray-700 dark:text-gray-300 font-mono">{{ $created->file }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ __('Columns created:') }} {{ implode(', ', $created->columns) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <form method="POST" action="{{ route('scripts.run', $definition->slug) }}" class="space-y-6"
                        @if ($formParams->contains(fn ($p) => $p->type->value === 'file')) enctype="multipart/form-data" @endif
                        @submit.prevent="submitRun($event)">
                        @csrf

                        @foreach ($formParams as $param)
                            <div>
                                <x-input-label for="{{ $param->name }}" :value="$param->name" />

                                @if ($param->type->value === 'enum')
                                    <select id="{{ $param->name }}" name="{{ $param->name }}" @if ($param->required) required @endif
                                        @if ($param->name === 'account') x-model="selectedAccount" @endif
                                        class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm w-full">
                                        @if (! $param->required)
                                            <option value="" @selected(old($param->name, $param->default) === null)></option>
                                        @endif
                                        @foreach ($param->options ?? [] as $option)
                                            <option value="{{ $option }}" @selected(old($param->name, $param->default) === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($param->type->value === 'bool')
                                    <label class="flex items-center gap-2 mt-1">
                                        <input type="checkbox" id="{{ $param->name }}" name="{{ $param->name }}" value="1"
                                            @checked(old($param->name, $param->default) === true)
                                            class="rounded border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ $param->help }}</span>
                                    </label>
                                @elseif ($param->type->value === 'int')
                                    <x-text-input id="{{ $param->name }}" name="{{ $param->name }}" type="number"
                                        value="{{ old($param->name, $param->default) }}"
                                        class="mt-1 block w-full" :required="$param->required" />
                                @elseif ($param->type->value === 'file')
                                    <input type="file" id="{{ $param->name }}" name="{{ $param->name }}" accept=".csv,.txt"
                                        @if ($param->required) required @endif
                                        class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 dark:file:bg-indigo-900 file:text-indigo-700 dark:file:text-indigo-200 hover:file:bg-indigo-100" />
                                    @if ($param->help)
                                        <div class="mt-2 p-3 bg-blue-50 dark:bg-blue-950 text-blue-800 dark:text-blue-200 rounded text-xs">
                                            {{ $param->help }}
                                        </div>
                                    @endif
                                @else
                                    <x-text-input id="{{ $param->name }}" name="{{ $param->name }}" type="text"
                                        value="{{ old($param->name, $param->default) }}"
                                        class="mt-1 block w-full" :required="$param->required" />
                                @endif

                                @if ($param->help && ! in_array($param->type->value, ['bool', 'file'], true))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $param->help }}</p>
                                @endif

                                <x-input-error :messages="$errors->get($param->name)" class="mt-2" />
                            </div>
                        @endforeach

                        <x-primary-button
                            x-bind:disabled="!isTerminal || connectionBlocked()"
                            x-bind:class="{ 'opacity-70 cursor-not-allowed': !isTerminal || connectionBlocked() }"
                            x-bind:title="connectionBlocked() ? '{{ __('Connection check failed for one or more accounts — fix credentials before running.') }}' : null"
                        >
                            <template x-if="isTerminal">
                                <span>{{ __('Run') }}</span>
                            </template>
                            <template x-if="!isTerminal">
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span>
                                        {{ __('Running') }}<template x-if="progressPercent !== null"><span> (<span x-text="progressPercent"></span>%)</span></template>
                                    </span>
                                </span>
                            </template>
                        </x-primary-button>
                    </form>
                </div>

            </div>

            {{-- Right column — live output. Read-only: no input of any kind lives
            here, this is purely a display surface for whatever the most recent
            run of this script (by anyone) has produced so far. --}}
            <div class="space-y-6 md:sticky md:top-6">
                @include('scripts._output-panel', [
                    'definition' => $definition,
                    'latestRun' => $latestRun,
                    'latestRunCanPromoteToLive' => $latestRunCanPromoteToLive,
                    'hasResumeParam' => $hasResumeParam,
                ])
            </div>

        </div>
    </div>
</x-app-layout>
