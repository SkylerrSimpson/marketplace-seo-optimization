@php
    $isTerminal = $run->status->isTerminal();
@endphp

<x-app-layout>
    @unless ($isTerminal)
        @push('head')
            <meta http-equiv="refresh" content="3">
        @endpush
    @endunless

    <x-slot name="header">
        <a href="{{ route('runs.index') }}"
           class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 hover:underline mb-2">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 111.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd" />
            </svg>
            {{ __('Back to Runs') }}
        </a>
        <x-breadcrumb :items="[
            ['label' => __('Runs'), 'url' => route('runs.index')],
            ['label' => $definition->title, 'url' => route('scripts.show', $definition->slug)],
            ['label' => __('Run').' #'.$run->id, 'url' => null],
        ]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Run') }} #{{ $run->id }} — {{ $definition->title }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 font-medium">{{ $run->status->value }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Exit code') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 font-medium">{{ $run->exit_code ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Ran by') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100">{{ $run->user->name }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Params') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100"><code class="break-all">{{ json_encode($run->params) }}</code></dd>
                    </div>
                </dl>
                @unless ($isTerminal)
                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('This page refreshes automatically while the run is in progress.') }}</p>
                @endunless

                @if ($canPromoteToLive)
                    <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                        <a href="{{ route('runs.confirm.create', $run) }}"
                           class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Promote to live') }}
                        </a>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('This preview was verified against eBay and succeeded — you can confirm and write it live from here.') }}</p>
                    </div>
                @endif

                @if ($run->preview_run_id)
                    <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Confirmed live from') }}
                        <a href="{{ route('runs.show', $run->preview_run_id) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('preview run') }} #{{ $run->preview_run_id }}</a>
                        — {{ __('typed') }} "<code>{{ $run->confirmation_text }}</code>" {{ __('at') }} {{ $run->confirmed_at }}.
                    </div>
                @endif
            </div>

            @if ($downloadableFiles->isNotEmpty())
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Output files') }}</h3>
                    <ul class="space-y-1">
                        @foreach ($downloadableFiles as $file)
                            <li>
                                <a href="{{ route('runs.download', ['run' => $run, 'filename' => $file]) }}"
                                   class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                    ⬇ {{ $file }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($run->stdout)
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Output') }}</h3>
                    <pre class="text-xs bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 p-4 rounded overflow-x-auto">{{ $run->stdout }}</pre>
                </div>
            @endif

            @if ($run->stderr)
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold text-red-700 dark:text-red-400 mb-2">{{ __('Errors') }}</h3>
                    <pre class="text-xs bg-red-50 dark:bg-red-950 text-red-800 dark:text-red-200 p-4 rounded overflow-x-auto">{{ $run->stderr }}</pre>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
