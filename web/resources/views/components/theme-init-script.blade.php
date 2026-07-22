@props(['theme' => 'system'])
{{-- Must run before first paint (placed early in <head>, before @vite's CSS
link) so there's no flash of the wrong theme. app.css redefines the `dark:`
variant to be class-driven (@custom-variant dark (&:where(.dark, .dark *))),
so nothing renders dark until this adds the class itself. --}}
<script>
    (function () {
        var theme = @json($theme);
        var media = window.matchMedia('(prefers-color-scheme: dark)');

        function apply() {
            var isDark = theme === 'dark' || (theme === 'system' && media.matches);
            document.documentElement.classList.toggle('dark', isDark);
        }

        apply();

        if (theme === 'system') {
            media.addEventListener('change', apply);
        }
    })();
</script>
