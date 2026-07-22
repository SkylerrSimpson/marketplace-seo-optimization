<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('code') · {{ config('app.name', 'DOWScripts') }}</title>
        <link rel="icon" href="{{ asset('DowsScriptsLogoGraphic.png') }}" type="image/png">

        {{-- Errors can render for a guest, so there's no stored preference — follow the OS. --}}
        <x-theme-init-script theme="system" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
        <div class="min-h-screen flex flex-col items-center justify-center px-6 text-center">
            <img src="{{ asset('DowsScriptsLogoGraphic.png') }}" alt="{{ config('app.name', 'DOWScripts') }}" class="h-12 w-auto mb-8 opacity-90">

            <p class="text-6xl font-bold text-indigo-600 dark:text-indigo-400 tabular-nums">@yield('code')</p>
            <h1 class="mt-4 text-2xl font-semibold text-gray-900 dark:text-gray-100">@yield('title')</h1>
            <p class="mt-3 max-w-md text-sm text-gray-600 dark:text-gray-400">@yield('message')</p>

            <div class="mt-8 flex items-center gap-3">
                @yield('actions')
                <a href="{{ url('/dashboard') }}"
                   class="inline-flex items-center px-4 py-2 rounded-md bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">
                    {{ __('Back to Dashboard') }}
                </a>
            </div>
        </div>
    </body>
</html>
