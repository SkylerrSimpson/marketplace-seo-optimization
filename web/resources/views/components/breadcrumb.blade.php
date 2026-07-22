@props(['items' => []])
{{--
    Reusable breadcrumb, dropped into any page's <x-slot name="header">.
    $items is an ordered list of ['label' => string, 'url' => ?string] —
    the last item should have url => null (the current page, not a link).
    "Home" (-> dashboard) is always prepended, so callers never repeat it.
--}}
<nav class="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 mb-1 flex-wrap" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}" class="hover:text-gray-600 dark:hover:text-gray-300 hover:underline">{{ __('Home') }}</a>
    @foreach ($items as $item)
        <span>/</span>
        @if ($item['url'] ?? null)
            <a href="{{ $item['url'] }}" class="hover:text-gray-600 dark:hover:text-gray-300 hover:underline">{{ $item['label'] }}</a>
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
