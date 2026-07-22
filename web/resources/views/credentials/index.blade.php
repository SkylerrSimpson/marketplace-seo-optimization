<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[['label' => __('Credentials'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Credentials') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status') === 'credential-updated')
                <div class="p-4 bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 rounded-lg">
                    {{ __('Credentials saved.') }}
                </div>
            @elseif (session('status') === 'credential-deleted')
                <div class="p-4 bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 rounded-lg">
                    {{ __('Credentials deleted.') }}
                </div>
            @elseif (session('status') === 'oauth-connected')
                <div class="p-4 bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 rounded-lg">
                    {{ __('Connected — the access token was saved automatically.') }}
                </div>
            @elseif (session('status') === 'oauth-declined')
                <div class="p-4 bg-amber-50 dark:bg-amber-900 text-amber-700 dark:text-amber-200 rounded-lg">
                    {{ __('Connection cancelled — nothing was changed.') }}
                </div>
            @elseif (session('status') === 'oauth-failed')
                <div class="p-4 bg-red-50 dark:bg-red-900 text-red-700 dark:text-red-200 rounded-lg">
                    {{ __('Connection failed. Check the app credentials and that the redirect URL is registered, then try again.') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                @if ($credentials->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('No marketplace accounts configured yet.') }}
                    </p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4">{{ __('Marketplace') }}</th>
                                <th class="py-2 pr-4">{{ __('Account') }}</th>
                                <th class="py-2 pr-4">{{ __('Fields set') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($credentials as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-700">
                                    <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $row['marketplace'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $row['account'] }}</td>
                                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">
                                        {{ $row['setCount'] }} {{ __('of') }} {{ $row['fieldCount'] }}
                                    </td>
                                    <td class="py-2">
                                        <div class="flex items-center gap-3">
                                            <a href="{{ route('credentials.edit', ['marketplace' => $row['marketplace'], 'account' => $row['account']]) }}"
                                               class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                                {{ __('Edit') }}
                                            </a>
                                            <form method="POST"
                                                  action="{{ route('credentials.destroy', ['marketplace' => $row['marketplace'], 'account' => $row['account']]) }}"
                                                  class="credential-delete-form">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:underline">
                                                    {{ __('Delete') }}
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ __('Add an account') }}</h3>

                {{-- marketplace uses @js(), not '{{ old(...) }}' — old() reflects
                whatever a failed submission sent, and {{ }} only HTML-escapes
                (not JS-escapes); the browser decodes that HTML-escaping back to
                a literal quote before Alpine evaluates the attribute, so a
                crafted ?marketplace= value could break out of the single-quoted
                JS string and execute arbitrary JS. @js() is safe in both
                contexts (unicode-escapes quotes rather than HTML-entity-escaping
                them) — see accountsByMarketplace below, done correctly from the
                start. --}}
                <form method="GET" action="{{ route('credentials.new') }}" class="flex flex-wrap items-end gap-4"
                      x-data="{
                          marketplace: @js(old('marketplace', $marketplaces[0] ?? '')),
                          accountsByMarketplace: @js($accountsByMarketplace),
                          accountsFor(mp) { return this.accountsByMarketplace[mp] || []; },
                          // A marketplace whose only possible account is the 'default'
                          // sentinel (single flat store, e.g. Shopify) has nothing for
                          // a person to actually choose — asking anyway just invites a
                          // typo that creates a row buildEnv() will never match.
                          isSingleAccount(mp) { const a = this.accountsFor(mp); return a.length === 1 && a[0] === 'default'; },
                      }">
                    <div>
                        <x-input-label for="marketplace" :value="__('Marketplace')" />
                        <select id="marketplace" name="marketplace" required x-model="marketplace"
                            class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm">
                            @foreach ($marketplaces as $marketplace)
                                <option value="{{ $marketplace }}">{{ $marketplace }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Exactly one name="account" field exists in the DOM at a
                    time — x-if (not x-show) between all three branches, since two
                    same-named fields would both submit and the later one in DOM
                    order would silently win, clobbering whichever was intended. --}}
                    <template x-if="!isSingleAccount(marketplace) && accountsFor(marketplace).length > 0">
                        <div>
                            <x-input-label for="account" :value="__('Account')" />
                            <select id="account" name="account" required
                                class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm">
                                <template x-for="acct in accountsFor(marketplace)" :key="acct">
                                    <option :value="acct" x-text="acct"></option>
                                </template>
                            </select>
                        </div>
                    </template>
                    <template x-if="!isSingleAccount(marketplace) && accountsFor(marketplace).length === 0">
                        <div>
                            <x-input-label for="account" :value="__('Account')" />
                            <x-text-input id="account" name="account" type="text" placeholder="account" required />
                        </div>
                    </template>
                    {{-- Single-account marketplaces (Shopify) still submit
                    account=default — just via a hidden field instead of asking
                    the person to type or pick something with only one possible
                    right answer. --}}
                    <template x-if="isSingleAccount(marketplace)">
                        <input type="hidden" name="account" value="default">
                    </template>

                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                @error('marketplace')
                    <x-input-error :messages="$message" class="mt-2" />
                @enderror
                @error('account')
                    <x-input-error :messages="$message" class="mt-2" />
                @enderror
            </div>

        </div>
    </div>

    <script>
        document.querySelectorAll('.credential-delete-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm('Delete these credentials? You will need to re-enter them to use this account again.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</x-app-layout>
