<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[['label' => __('Backups'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Backups') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('backupGate'))
                @php($gate = session('backupGate'))
                <div class="p-4 bg-amber-50 dark:bg-amber-950 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-100 rounded-lg text-sm">
                    <p class="font-semibold">{{ __('A backup is required before writing live to :account.', ['account' => ucfirst($gate['account'])]) }}</p>
                    <p class="mt-1">{{ __('Run "Backup Current State" for :account below, then return to your run to confirm the live write.', ['account' => ucfirst($gate['account'])]) }}
                        <a href="{{ route('runs.show', $gate['runId']) }}" class="font-medium underline hover:no-underline">{{ __('Back to your run') }} &rarr;</a>
                    </p>
                </div>
            @endif

            <div class="p-4 bg-blue-50 dark:bg-blue-950 text-blue-800 dark:text-blue-200 rounded-lg text-sm">
                {{ __('A write-type script can only go live for an account that has at least one backup below. No backup yet? Run "Backup Current State" for that account first — it\'s a read-only script, listed on the eBay scripts page.') }}
            </div>

            @foreach ($accounts as $accountData)
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">{{ ucfirst($accountData['account']) }}</h3>
                        @if ($accountData['hasBackup'])
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                {{ __('Backed up — writes allowed') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                {{ __('No backup — writes blocked') }}
                            </span>
                        @endif
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Backups') }}</h4>
                        @if (empty($accountData['backups']))
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No backups yet for this account.') }}</p>
                        @else
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th class="py-2 pr-4">{{ __('Name') }}</th>
                                        <th class="py-2 pr-4">{{ __('Created') }}</th>
                                        <th class="py-2 pr-4">{{ __('Files') }}</th>
                                        <th class="py-2 pr-4">{{ __('Size') }}</th>
                                        <th class="py-2">{{ __('Docs') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($accountData['backups'] as $backup)
                                        <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                            <td class="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $backup['name'] }}</td>
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($backup['createdAt'])->diffForHumans() }}</td>
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $backup['fileCount'] }}</td>
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ number_format($backup['totalBytes'] / 1024, 0) }} KB</td>
                                            <td class="py-2 text-gray-500 dark:text-gray-400">
                                                @if ($backup['hasManifest']) {{ __('manifest.json') }} @endif
                                                @if ($backup['hasReadme']) {{ __('README') }} @endif
                                                @if (! $backup['hasManifest'] && ! $backup['hasReadme']) — @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Recent output files') }}</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('Current working CSVs — not point-in-time snapshots, these get overwritten every time a script runs.') }}</p>
                        @if (empty($accountData['outputFiles']))
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No output files yet for this account.') }}</p>
                        @else
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th class="py-2 pr-4">{{ __('File') }}</th>
                                        <th class="py-2 pr-4">{{ __('Modified') }}</th>
                                        <th class="py-2 pr-4">{{ __('Size') }}</th>
                                        <th class="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (array_slice($accountData['outputFiles'], 0, 20) as $file)
                                        <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                            <td class="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $file['name'] }}</td>
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($file['modifiedAt'])->diffForHumans() }}</td>
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ number_format($file['sizeBytes'] / 1024, 0) }} KB</td>
                                            <td class="py-2">
                                                <a href="{{ route('backups.download-output', ['account' => $accountData['account'], 'filename' => $file['name']]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                                    ⬇ {{ __('Download') }}
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            @endforeach

        </div>
    </div>
</x-app-layout>
