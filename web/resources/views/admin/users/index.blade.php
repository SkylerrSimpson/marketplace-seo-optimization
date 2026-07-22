<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[['label' => __('Admin'), 'url' => null], ['label' => __('Users'), 'url' => null]]" />
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Users') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status') === 'user-created')
                <div class="p-3 rounded-md bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 text-sm">
                    {{ __('User created.') }}
                </div>
            @elseif (session('status') === 'user-updated')
                <div class="p-3 rounded-md bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 text-sm">
                    {{ __('User updated.') }}
                </div>
            @endif

            {{-- Existing users --}}
            <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Name') }}</th>
                            <th class="py-2 pr-4">{{ __('Email') }}</th>
                            <th class="py-2 pr-4">{{ __('Role') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr class="border-b border-gray-100 dark:border-gray-700 last:border-0">
                                <td class="py-2 pr-4 text-gray-800 dark:text-gray-200">
                                    {{ $user->name }}
                                    @if ($user->is($currentUser = auth()->user()))
                                        <span class="text-xs text-gray-400 dark:text-gray-500">({{ __('you') }})</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                                <td class="py-2 pr-4">
                                    @if ($user->is_admin)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('Admin') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('Member') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($user->is_active)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('Active') }}</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ __('Deactivated') }}</span>
                                    @endif
                                    @unless ($user->hasVerifiedEmail())
                                        {{-- Signed up but never confirmed their email — the tell-tale of a
                                             junk/abandoned self-registration worth a moderator's eye. --}}
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" title="{{ __('Has not confirmed their email address') }}">{{ __('Unverified') }}</span>
                                    @endunless
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    {{-- You can never change your own role or active state — the
                                         self-lockout guard (see UserController) — so those controls
                                         simply aren't offered for your own row. --}}
                                    @unless ($user->hasVerifiedEmail())
                                        {{-- Escape hatch when a verification email never arrived — without
                                             this a broken mailer locks the user out of the whole app. --}}
                                        <form method="POST" action="{{ route('admin.users.verify', $user) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="text-xs text-amber-600 dark:text-amber-400 hover:underline">
                                                {{ __('Verify') }}
                                            </button>
                                        </form>
                                        <span class="text-gray-300 dark:text-gray-600">·</span>
                                    @endunless
                                    @unless ($user->is(auth()->user()))
                                        <form method="POST" action="{{ route('admin.users.toggle-admin', $user) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                                {{ $user->is_admin ? __('Revoke admin') : __('Make admin') }}
                                            </button>
                                        </form>
                                        <span class="text-gray-300 dark:text-gray-600">·</span>
                                        <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="text-xs {{ $user->is_active ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }} hover:underline">
                                                {{ $user->is_active ? __('Deactivate') : __('Reactivate') }}
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-300 dark:text-gray-600">{{ __('you') }}</span>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Add a user --}}
            <div class="p-4 sm:p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-4">{{ __('Add a user directly') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    {{ __('People normally sign up themselves from the login page. Use this only to create an account on someone\'s behalf — it skips email verification, sets the password you enter here, and they can change it from their profile afterward.') }}
                </p>

                <form method="POST" action="{{ route('admin.users.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="email" :value="__('Email')" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="password_confirmation" :value="__('Confirm password')" />
                        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-2">
                        <input id="is_admin" name="is_admin" type="checkbox" value="1" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm" @checked(old('is_admin')) />
                        <label for="is_admin" class="text-sm text-gray-600 dark:text-gray-400">{{ __('Make this user an admin (can manage users and see all activity)') }}</label>
                    </div>
                    <div class="sm:col-span-2">
                        <x-primary-button>{{ __('Create user') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
