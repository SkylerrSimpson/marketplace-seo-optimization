<x-app-layout>
    @if ($runs->contains(fn ($r) => ! $r->status->isTerminal()))
        @push('head')
            <meta http-equiv="refresh" content="3">
        @endpush
    @endif

    <x-slot name="header">
        <x-breadcrumb :items="[['label' => __('Admin'), 'url' => null], ['label' => __('All activity'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('All activity') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Every teammate\'s runs, across all accounts — the audit view of what has hit the live stores. Read-only: you can inspect any run, but only its owner can cancel, resume, or promote it.') }}
            </p>

            <form method="GET" action="{{ route('admin.runs.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="status" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('Status') }}</label>
                    <select id="status" name="status" onchange="this.form.submit()"
                        class="text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($activeStatus === $status->value)>{{ ucfirst($status->value) }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($activeStatus)
                    <a href="{{ route('admin.runs.index') }}" class="text-sm text-gray-500 dark:text-gray-400 hover:underline pb-2">{{ __('Clear') }}</a>
                @endif
            </form>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                @if ($runs->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No runs yet.') }}</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4">{{ __('Script') }}</th>
                                <th class="py-2 pr-4">{{ __('Status') }}</th>
                                <th class="py-2 pr-4">{{ __('Ran by') }}</th>
                                <th class="py-2">{{ __('Queued') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($runs as $run)
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

                    <div class="mt-4">
                        {{ $runs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
