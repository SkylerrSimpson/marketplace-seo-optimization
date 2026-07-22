<x-app-layout>
    @if ($recentRuns->contains(fn ($r) => ! $r->status->isTerminal()))
        @push('head')
            <meta http-equiv="refresh" content="3">
        @endpush
    @endif

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- First-run onboarding: no credentials anywhere means a brand-new
                 install. Point the user at the two things they need before
                 anything else works, instead of three empty tables. --}}
            @if ($credentialSummary->isEmpty())
                <div class="p-6 bg-indigo-50 dark:bg-indigo-950 border border-indigo-200 dark:border-indigo-900 rounded-lg">
                    <h3 class="text-base font-semibold text-indigo-900 dark:text-indigo-100">{{ __('Welcome to DOWScripts') }}</h3>
                    <p class="mt-1 text-sm text-indigo-800 dark:text-indigo-200">{{ __('Two quick steps to get going:') }}</p>
                    <ol class="mt-3 space-y-2 text-sm text-indigo-800 dark:text-indigo-200 list-decimal list-inside">
                        <li>{{ __('Add credentials for a marketplace so scripts can reach it.') }}</li>
                        <li>{{ __('Open a script, run it in preview mode, then confirm to write live.') }}</li>
                    </ol>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('credentials.index') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">{{ __('Add credentials') }}</a>
                        <a href="{{ route('scripts.index') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-white dark:bg-gray-800 border border-indigo-200 dark:border-indigo-800 text-indigo-700 dark:text-indigo-300 text-sm font-medium hover:bg-indigo-100 dark:hover:bg-indigo-900 transition">{{ __('Browse scripts') }}</a>
                    </div>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Recent runs') }}</h3>
                    <a href="{{ route('runs.index') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('View all') }}</a>
                </div>

                @if ($recentRuns->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No runs yet.') }}
                        <a href="{{ route('scripts.index') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Browse scripts') }} &rarr;</a>
                    </p>
                @else
                    <table class="w-full text-sm text-left">
                        <tbody>
                            @foreach ($recentRuns as $run)
                                <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                    <td class="py-2 pr-4">
                                        <a href="{{ route('runs.show', $run) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                            {{ $registry->findOrNull($run->script_slug)?->title ?? $run->script_slug }}
                                        </a>
                                    </td>
                                    <td class="py-2 pr-4">@include('runs._status-pill', ['run' => $run])</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $run->user->name }}</td>
                                    <td class="py-2 text-gray-500 dark:text-gray-400">{{ $run->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Credential status') }}</h3>
                    <a href="{{ route('credentials.index') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Manage') }}</a>
                </div>

                @if ($credentialSummary->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No marketplace accounts configured yet.') }}
                        <a href="{{ route('credentials.index') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Add one') }} &rarr;</a>
                    </p>
                @else
                    <table class="w-full text-sm text-left">
                        <tbody>
                            @foreach ($credentialSummary as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                    <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $row['marketplace'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $row['account'] }}</td>
                                    <td class="py-2 text-gray-500 dark:text-gray-400">{{ $row['setCount'] }} {{ __('of') }} {{ $row['fieldCount'] }} {{ __('fields set') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($connectionMarketplaces->isNotEmpty())
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg"
                     x-data="{
                        items: {{ Illuminate\Support\Js::from($connectionMarketplaces) }}.map((m) => ({ marketplace: m, status: 'checking', accounts: {} })),
                        init() {
                            this.items.forEach((item) => {
                                fetch(`/connection-check/${item.marketplace}`)
                                    .then((r) => (r.ok ? r.json() : { error: 'unreachable' }))
                                    .then((d) => { if (d.accounts) { item.accounts = d.accounts; item.status = 'done'; } else { item.status = 'error'; } })
                                    .catch(() => { item.status = 'error'; });
                            });
                        },
                     }" x-init="init()">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">{{ __('Connection status') }}</h3>
                    <div class="space-y-3">
                        <template x-for="item in items" :key="item.marketplace">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="font-medium text-gray-700 dark:text-gray-300 capitalize w-20" x-text="item.marketplace"></span>
                                <template x-if="item.status === 'checking'">
                                    <span class="text-gray-400 dark:text-gray-500">{{ __('Checking…') }}</span>
                                </template>
                                <template x-if="item.status === 'error'">
                                    <span class="text-amber-600 dark:text-amber-400">{{ __('Couldn’t reach') }}</span>
                                </template>
                                <template x-if="item.status === 'done'">
                                    <span class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                        <template x-for="(result, account) in item.accounts" :key="account">
                                            <span class="inline-flex items-center gap-1.5" :title="result.ok ? null : result.detail">
                                                <span class="w-2 h-2 rounded-full" :class="result.ok ? 'bg-green-500' : 'bg-red-500'"></span>
                                                <span class="text-gray-600 dark:text-gray-400" x-text="account"></span>
                                            </span>
                                        </template>
                                    </span>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Backup coverage') }}</h3>
                    <a href="{{ route('backups.index') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Manage') }}</a>
                </div>

                <table class="w-full text-sm text-left">
                    <tbody>
                        @foreach ($backupStatus as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ __('ebay') }} / {{ $row['account'] }}</td>
                                <td class="py-2">
                                    @if ($row['hasBackup'])
                                        <span class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300">{{ __('Backed up — writes allowed') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900 px-2 py-0.5 text-xs font-medium text-red-700 dark:text-red-300">{{ __('No backup — writes blocked') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>
