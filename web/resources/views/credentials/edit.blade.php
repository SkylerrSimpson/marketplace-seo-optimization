<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[
            ['label' => __('Credentials'), 'url' => route('credentials.index')],
            ['label' => ucfirst($marketplace).' / '.$account, 'url' => null],
        ]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit credentials') }} — {{ $marketplace }} / {{ $account }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (!empty($instructions))
                <div class="p-4 sm:p-6 bg-indigo-50 dark:bg-indigo-950 border border-indigo-100 dark:border-indigo-900 rounded-lg">
                    <h3 class="text-sm font-medium text-indigo-900 dark:text-indigo-200 mb-2">{{ __('How to get these values') }}</h3>
                    <ol class="list-decimal list-inside space-y-1.5 text-sm text-indigo-800 dark:text-indigo-300">
                        @foreach ($instructions as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if ($supportsOAuth)
                <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Connect automatically') }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        {{ __('Approve access in a Shopify window and the token fills in for you — no copy-pasting. (One-time setup: the app\'s redirect URL must be registered in Shopify\'s admin.)') }}
                    </p>
                    <a href="{{ route('oauth.authorize', ['marketplace' => $marketplace]) }}"
                       class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">
                        {{ __('Connect with :marketplace', ['marketplace' => ucfirst($marketplace)]) }}
                    </a>
                </div>

                <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                    {{ __('or enter values manually') }}
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">

                @if (empty($knownFields))
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('No known credential fields for this marketplace yet.') }}
                    </p>
                @else
                    <form method="POST" action="{{ route('credentials.update', ['marketplace' => $marketplace, 'account' => $account]) }}" class="space-y-6" id="credential-edit-form">
                        @csrf
                        @method('PUT')

                        @foreach ($knownFields as $field)
                            <div>
                                <div class="flex items-center gap-2">
                                    <x-input-label for="{{ $field }}" :value="$field" />
                                    @if ($isSet[$field])
                                        <span class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300">{{ __('Set') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Not set') }}</span>
                                    @endif
                                </div>
                                <x-text-input
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    type="text"
                                    class="mt-1 block w-full"
                                    autocomplete="off"
                                    data-was-set="{{ $isSet[$field] ? '1' : '0' }}"
                                    placeholder="{{ $isSet[$field] ? '•••• (set — leave blank to keep)' : '(not set)' }}"
                                />
                                <x-input-error :messages="$errors->get($field)" class="mt-2" />
                            </div>
                        @endforeach

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                            <a href="{{ route('credentials.index') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">
                                {{ __('Cancel') }}
                            </a>
                        </div>
                    </form>
                @endif

            </div>
        </div>
    </div>

    <script>
        (function () {
            var form = document.getElementById('credential-edit-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                var overwritten = Array.prototype.filter.call(
                    form.querySelectorAll('[data-was-set="1"]'),
                    function (el) { return el.value.trim() !== ''; }
                );
                if (overwritten.length === 0) return;
                var names = overwritten.map(function (el) { return el.name; }).join(', ');
                if (!confirm('This will overwrite the existing value for: ' + names + '. Continue?')) {
                    e.preventDefault();
                }
            });
        })();
    </script>
</x-app-layout>
