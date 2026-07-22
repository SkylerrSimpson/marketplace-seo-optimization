@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1.5 bg-white dark:bg-gray-800'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$width = match ($width) {
    '48' => 'w-48',
    default => $width,
};
@endphp

<div class="relative" x-data="{ open: false, closeTimer: null }" @click.outside="open = false" @close.stop="open = false"
        @mouseenter="clearTimeout(closeTimer); open = true"
        @mouseleave="closeTimer = setTimeout(() => open = false, 200)">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
            class="absolute z-50 mt-2 {{ $width }} {{ $alignmentClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="rounded-xl border border-gray-100 dark:border-gray-700 shadow-lg shadow-gray-200/50 dark:shadow-black/30 overflow-hidden {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
