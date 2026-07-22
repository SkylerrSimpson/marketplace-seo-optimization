<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[['label' => __('Settings'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Settings') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Appearance') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    {{ __('"System" follows your OS/browser setting and switches automatically if it changes.') }}
                </p>

                {{-- Server-rendered initial state (correct on first paint, no JS
                required) — x-bind:class takes over live updates once Alpine
                initializes, same pattern as the script-run status pill elsewhere. --}}
                <div
                    x-data="{
                        theme: @js($theme),
                        setTheme(value) {
                            this.theme = value;
                            const isDark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                            document.documentElement.classList.toggle('dark', isDark);
                            fetch('{{ route('settings.theme') }}', {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                },
                                body: JSON.stringify({ theme: value }),
                            });
                        },
                    }"
                    class="flex items-center gap-2"
                >
                    @foreach (['light' => __('Light'), 'dark' => __('Dark'), 'system' => __('System')] as $value => $label)
                        <button type="button" @click="setTheme('{{ $value }}')"
                            @class([
                                'px-3 py-1.5 rounded-full text-sm font-medium',
                                'bg-indigo-600 text-white' => $theme === $value,
                                'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $theme !== $value,
                            ])
                            x-bind:class="{
                                'bg-indigo-600 text-white': theme === '{{ $value }}',
                                'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600': theme !== '{{ $value }}',
                            }"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
