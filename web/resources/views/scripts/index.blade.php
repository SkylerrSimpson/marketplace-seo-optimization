<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="$activeMarketplace
            ? [['label' => ucfirst($activeMarketplace), 'url' => route('scripts.index', ['marketplace' => $activeMarketplace])], ['label' => __('All Scripts'), 'url' => null]]
            : [['label' => __('Scripts'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Scripts') }}
            @if ($activeMarketplace)
                <span class="text-gray-400 dark:text-gray-500 font-normal">— {{ $activeMarketplace }}</span>
            @endif
            @if ($activeType)
                <span class="text-gray-400 dark:text-gray-500 font-normal">({{ $activeType->value }})</span>
            @endif
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-10">

            {{-- Type filter — was previously only reachable by hand-editing
            ?type= in the URL despite being fully wired server-side
            (ScriptController::index()). Preserves the active marketplace so
            switching type doesn't also reset which marketplace you're looking at. --}}
            <div class="flex items-center gap-2 text-sm">
                {{-- A plain [null => ..] array key would silently cast null to
                '' (PHP array keys can't be null), breaking the === $value
                comparison below for "All" — an explicit list of pairs avoids
                that trap entirely. --}}
                @foreach ([['value' => null, 'label' => __('All')], ['value' => 'read', 'label' => __('Read-only')], ['value' => 'write', 'label' => __('Write')]] as $option)
                    <a href="{{ route('scripts.index', array_filter(['marketplace' => $activeMarketplace, 'type' => $option['value']])) }}"
                       @class([
                           'px-3 py-1 rounded-full font-medium',
                           'bg-indigo-600 text-white' => $activeType?->value === $option['value'],
                           'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $activeType?->value !== $option['value'],
                       ])>
                        {{ $option['label'] }}
                    </a>
                @endforeach
            </div>

            @forelse ($byMarketplace as $marketplace => $byCategory)
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-4">
                        {{ $marketplace }}
                    </h3>

                    {{-- Each category is its own card, two per row — a category
                    added later (a marketplace whose feed isn't this one's aspects
                    + descriptions shape) just lands in the next cell, wrapping to
                    a new row below rather than growing one column forever. --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach ($byCategory as $category => $split)
                            <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg space-y-6">
                                @if ($split['pipeline']->isNotEmpty())
                                    <div>
                                        <div class="mb-2">
                                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                                {{ $category }}
                                            </h4>
                                            @if ($split['pipeline']->count() > 1)
                                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Recommended order — optional steps are marked; skip what you don\'t need.') }}</p>
                                            @endif
                                        </div>
                                        <ol class="divide-y divide-gray-100 dark:divide-gray-700">
                                            @foreach ($split['pipeline'] as $group)
                                                @if ($group['scripts']->count() > 1)
                                                    <li class="py-3 flex items-start gap-3">
                                                        <span class="mt-0.5 flex-shrink-0 w-5 h-5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs font-medium flex items-center justify-center">
                                                            {{ $group['step'] }}
                                                        </span>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="text-xs text-gray-400 dark:text-gray-500 mb-1">{{ __('Choose one:') }}</p>
                                                            <ol class="space-y-2">
                                                                @foreach ($group['scripts'] as $script)
                                                                    @include('scripts._script-list-item', ['script' => $script, 'hideStepBadge' => true])
                                                                @endforeach
                                                            </ol>
                                                        </div>
                                                    </li>
                                                @else
                                                    @include('scripts._script-list-item', ['script' => $group['scripts']->first()])
                                                @endif
                                            @endforeach
                                        </ol>
                                    </div>
                                @endif

                                @if ($split['standalone']->isNotEmpty())
                                    <div>
                                        <div class="flex items-baseline justify-between mb-1">
                                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                                {{ $split['pipeline']->isNotEmpty() ? __(':category — other tools', ['category' => $category]) : $category }}
                                            </h4>
                                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('Not part of a sequence.') }}</p>
                                        </div>
                                        <ol class="divide-y divide-gray-100 dark:divide-gray-700">
                                            @foreach ($split['standalone'] as $script)
                                                @include('scripts._script-list-item', ['script' => $script])
                                            @endforeach
                                        </ol>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No scripts registered yet.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
