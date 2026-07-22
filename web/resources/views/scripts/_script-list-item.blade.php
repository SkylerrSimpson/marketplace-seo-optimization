<li class="py-3 flex items-start gap-3">
    @if ($script->step !== null && !($hideStepBadge ?? false))
        <span class="mt-0.5 flex-shrink-0 w-5 h-5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs font-medium flex items-center justify-center">
            {{ $script->step }}
        </span>
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 flex-wrap">
            <a href="{{ route('scripts.show', $script->slug) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ $script->title }}
            </a>
            <span @class([
                'px-2 py-0.5 rounded text-xs font-medium',
                'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $script->type->value === 'read',
                'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' => $script->type->value === 'write',
            ])>
                {{ $script->type->value }}
            </span>
            @if ($script->optional)
                <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                    {{ __('optional') }}
                </span>
            @endif
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ str($script->description)->limit(140) }}</p>
    </div>
</li>
