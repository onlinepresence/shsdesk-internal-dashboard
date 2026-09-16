@props([])

{{-- Theme toggle (plain JS + localStorage only, no Livewire/Alpine state). --}}
{{-- Reuse anywhere, e.g. future sidebar: <x-theme-toggle /> --}}
<button
    type="button"
    data-theme-toggle
    onclick="window.ControlDeskToggleTheme()"
    aria-label="Toggle dark mode"
    title="Toggle dark mode"
    {{ $attributes->merge(['class' => 'inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-300 bg-white text-mist shadow-sm transition hover:border-brand hover:text-brand focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 dark:border-white/15 dark:bg-deep dark:text-slate-200 dark:hover:border-accent dark:hover:text-white dark:focus:ring-accent dark:focus:ring-offset-ink']) }}
>
    {{-- Moon: shown in light mode --}}
    <svg data-theme-toggle-icon="moon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
        <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z" />
    </svg>
    {{-- Sun: shown in dark mode --}}
    <svg data-theme-toggle-icon="sun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="hidden h-5 w-5">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
    </svg>
</button>

@once
<script>
    (function () {
        if (window.ControlDeskToggleTheme) {
            window.ControlDeskSyncThemeToggle();
            return;
        }

        window.ControlDeskSyncThemeToggle = function () {
            var isDark = document.documentElement.classList.contains('dark');
            document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
                var moon = btn.querySelector('[data-theme-toggle-icon="moon"]');
                var sun = btn.querySelector('[data-theme-toggle-icon="sun"]');
                if (moon) {
                    moon.classList.toggle('hidden', isDark);
                }
                if (sun) {
                    sun.classList.toggle('hidden', !isDark);
                }
                btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            });
        };

        window.ControlDeskToggleTheme = function () {
            var isDark = document.documentElement.classList.toggle('dark');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch (e) {}
            window.ControlDeskSyncThemeToggle();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', window.ControlDeskSyncThemeToggle);
        } else {
            window.ControlDeskSyncThemeToggle();
        }

        // Keep icons in sync if the `dark` class changes from another toggle instance.
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.attributeName === 'class') {
                        window.ControlDeskSyncThemeToggle();
                    }
                });
            }).observe(document.documentElement, { attributes: true });
        }
    })();
</script>
@endonce
