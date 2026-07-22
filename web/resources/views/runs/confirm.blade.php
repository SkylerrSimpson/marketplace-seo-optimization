<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[
            ['label' => __('Runs'), 'url' => route('runs.index')],
            ['label' => $definition->title, 'url' => route('scripts.show', $definition->slug)],
            ['label' => __('Run').' #'.$run->id, 'url' => route('runs.show', $run)],
            ['label' => __('Confirm'), 'url' => null],
        ]" />
        <h2 class="font-semibold text-xl text-red-700 dark:text-red-400 leading-tight">
            {{ __('Confirm live write') }} — {{ __('Run') }} #{{ $run->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 bg-red-50 dark:bg-red-950 text-red-800 dark:text-red-200 rounded-lg text-sm">
                {{ __('This will write live to production eBay. This preview run (params below) was validated with --verify, but nothing has been written yet — this is the only step that actually does.') }}
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Preview params') }}</h3>
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 p-4 rounded overflow-x-auto">{{ json_encode($run->params, JSON_PRETTY_PRINT) }}</pre>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <form method="POST" action="{{ route('runs.confirm.store', $run) }}" class="space-y-6">
                    @csrf

                    @if ($mode->name === 'Single')
                        <div>
                            <x-input-label for="confirmation" :value="__('Retype the item ID to confirm: ') . $itemId" />
                            <x-text-input id="confirmation" name="confirmation" type="text" class="mt-1 block w-full" autocomplete="off" required />
                        </div>
                    @else
                        <div>
                            <x-input-label for="confirmation" :value="__('This affects multiple items. Type WRITE (exactly) to confirm.')" />
                            <x-text-input id="confirmation" name="confirmation" type="text" class="mt-1 block w-full" autocomplete="off" required />
                        </div>
                    @endif

                    <x-input-error :messages="$errors->get('confirmation')" class="mt-2" />

                    <div class="flex items-center gap-4">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Write live') }}
                        </button>
                        <a href="{{ route('runs.show', $run) }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">
                            {{ __('Cancel') }}
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
