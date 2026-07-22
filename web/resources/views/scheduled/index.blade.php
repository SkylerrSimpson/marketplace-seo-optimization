<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[['label' => __('Scheduled'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Scheduled runs') }}
        </h2>
    </x-slot>

    @php
        // slug => its account-param options (empty if the script takes no account),
        // so the create form can show the right account picker per script.
        $scriptAccounts = [];
        foreach ($schedulableScripts as $s) {
            $ap = collect($s->params)->firstWhere('name', 'account');
            $scriptAccounts[$s->slug] = $ap?->options ?? [];
        }
    @endphp

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 bg-blue-50 dark:bg-blue-950 text-blue-800 dark:text-blue-200 rounded-lg text-sm">
                {{ __('Schedule read-only scripts (audits, exports) to run automatically. Live-write scripts can’t be scheduled — those always require a person to confirm.') }}
            </div>

            @if (session('status') === 'schedule-created')
                <div class="p-3 bg-green-50 dark:bg-green-950 text-green-800 dark:text-green-200 rounded-lg text-sm">{{ __('Schedule created.') }}</div>
            @endif

            {{-- Create form --}}
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg"
                 x-data="{
                    scriptAccounts: {{ Illuminate\Support\Js::from($scriptAccounts) }},
                    slug: @js(old('script_slug', $schedulableScripts->first()?->slug)),
                    frequency: @js(old('frequency', 'daily')),
                    get accounts() { return this.scriptAccounts[this.slug] || []; },
                 }">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">{{ __('New schedule') }}</h3>

                <form method="POST" action="{{ route('scheduled.store') }}" class="space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="script_slug" :value="__('Script')" />
                        <select id="script_slug" name="script_slug" x-model="slug" required
                            class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                            @foreach ($schedulableScripts as $s)
                                <option value="{{ $s->slug }}">{{ $s->title }} ({{ ucfirst($s->marketplace) }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('script_slug')" class="mt-2" />
                    </div>

                    <div x-show="accounts.length" x-cloak>
                        <x-input-label for="account" :value="__('Account')" />
                        <select id="account" name="account" x-model="account"
                            class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                            <template x-for="acc in accounts" :key="acc">
                                <option :value="acc" x-text="acc"></option>
                            </template>
                        </select>
                        <x-input-error :messages="$errors->get('account')" class="mt-2" />
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <div>
                            <x-input-label for="frequency" :value="__('Frequency')" />
                            <select id="frequency" name="frequency" x-model="frequency"
                                class="mt-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                                <option value="hourly">{{ __('Hourly') }}</option>
                                <option value="daily">{{ __('Daily') }}</option>
                                <option value="weekly">{{ __('Weekly (Mondays)') }}</option>
                            </select>
                        </div>
                        <div x-show="frequency !== 'hourly'" x-cloak>
                            <x-input-label for="hour" :value="__('At hour (24h, server time)')" />
                            <select id="hour" name="hour"
                                class="mt-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">
                                @for ($h = 0; $h < 24; $h++)
                                    <option value="{{ $h }}" @selected(old('hour', 6) == $h)>{{ sprintf('%02d:00', $h) }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <x-primary-button type="submit">{{ __('Create schedule') }}</x-primary-button>
                </form>
            </div>

            {{-- Existing schedules --}}
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">{{ __('Active schedules') }}</h3>

                @if ($scheduledRuns->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing scheduled yet.') }}</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4">{{ __('Script') }}</th>
                                <th class="py-2 pr-4">{{ __('Account') }}</th>
                                <th class="py-2 pr-4">{{ __('Schedule') }}</th>
                                <th class="py-2 pr-4">{{ __('Last run') }}</th>
                                <th class="py-2 pr-4">{{ __('State') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($scheduledRuns as $scheduled)
                                <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                    <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $registry->findOrNull($scheduled->script_slug)?->title ?? $scheduled->script_slug }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $scheduled->params['account'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400"><code class="text-xs">{{ $scheduled->cron }}</code></td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $scheduled->last_run_at?->diffForHumans() ?? __('never') }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($scheduled->enabled)
                                            <span class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300">{{ __('Enabled') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Paused') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right whitespace-nowrap">
                                        <form method="POST" action="{{ route('scheduled.toggle', $scheduled) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ $scheduled->enabled ? __('Pause') : __('Enable') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('scheduled.destroy', $scheduled) }}" class="inline ml-3"
                                              onsubmit="return confirm('{{ __('Delete this schedule?') }}')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline">{{ __('Delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
