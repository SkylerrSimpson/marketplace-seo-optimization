<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <img src="{{ asset('DowsScriptsLogoGraphic.png') }}" alt="{{ config('app.name', 'DOWScripts') }}" class="block h-9 w-auto">
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('scripts.index')" :active="request()->routeIs('scripts.*') && ! request()->has('marketplace')">
                        {{ __('Scripts') }}
                    </x-nav-link>
                    <x-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">
                        {{ __('Credentials') }}
                    </x-nav-link>
                    <x-nav-link :href="route('runs.index')" :active="request()->routeIs('runs.*')">
                        {{ __('Runs') }}
                    </x-nav-link>
                    <x-nav-link :href="route('scheduled.index')" :active="request()->routeIs('scheduled.*')">
                        {{ __('Scheduled') }}
                    </x-nav-link>
                    <x-nav-link :href="route('backups.index')" :active="request()->routeIs('backups.*')">
                        {{ __('Backups') }}
                    </x-nav-link>

                    @if (Auth::user()->is_admin)
                        <div class="flex items-center">
                            <x-dropdown align="left" width="48">
                                <x-slot name="trigger">
                                    <button type="button" @class([
                                        'inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out',
                                        'border-b-2 border-indigo-400 text-gray-900 dark:text-gray-100' => request()->routeIs('admin.*'),
                                        'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' => ! request()->routeIs('admin.*'),
                                    ])>
                                        {{ __('Admin') }}
                                        <svg class="ms-1 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link :href="route('admin.users.index')">
                                        {{ __('Users') }}
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('admin.runs.index')">
                                        {{ __('All activity') }}
                                    </x-dropdown-link>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endif

                    @foreach ($navMarketplaces as $marketplace)
                        <div class="flex items-center">
                            <x-dropdown align="left" width="48">
                                <x-slot name="trigger">
                                    <button type="button" @class([
                                        'inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 focus:outline-none transition duration-150 ease-in-out',
                                        'border-b-2 border-indigo-400 text-gray-900 dark:text-gray-100' => request()->query('marketplace') === $marketplace,
                                        'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' => request()->query('marketplace') !== $marketplace,
                                    ])>
                                        {{ ucfirst($marketplace) }}
                                        <svg class="ms-1 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link :href="route('scripts.index', ['marketplace' => $marketplace])">
                                        {{ __('All scripts') }}
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('scripts.index', ['marketplace' => $marketplace, 'type' => 'read'])">
                                        {{ __('Read') }}
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('scripts.index', ['marketplace' => $marketplace, 'type' => 'write'])">
                                        {{ __('Write') }}
                                    </x-dropdown-link>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('settings.edit')">
                            {{ __('Settings') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('scripts.index')" :active="request()->routeIs('scripts.*') && ! request()->has('marketplace')">
                {{ __('All scripts') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">
                {{ __('Credentials') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('runs.index')" :active="request()->routeIs('runs.*')">
                {{ __('Runs') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('scheduled.index')" :active="request()->routeIs('scheduled.*')">
                {{ __('Scheduled') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('backups.index')" :active="request()->routeIs('backups.*')">
                {{ __('Backups') }}
            </x-responsive-nav-link>

            @if (Auth::user()->is_admin)
                <x-responsive-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                    {{ __('Admin · Users') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.runs.index')" :active="request()->routeIs('admin.runs.*')">
                    {{ __('Admin · All activity') }}
                </x-responsive-nav-link>
            @endif

            @foreach ($navMarketplaces as $marketplace)
                <div class="pt-2 mt-2 border-t border-gray-200 dark:border-gray-600">
                    <div class="px-4 text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">{{ ucfirst($marketplace) }}</div>
                    <x-responsive-nav-link :href="route('scripts.index', ['marketplace' => $marketplace])">
                        {{ __('All scripts') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('scripts.index', ['marketplace' => $marketplace, 'type' => 'read'])">
                        {{ __('Read') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('scripts.index', ['marketplace' => $marketplace, 'type' => 'write'])">
                        {{ __('Write') }}
                    </x-responsive-nav-link>
                </div>
            @endforeach
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('settings.edit')">
                    {{ __('Settings') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
