<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <!-- Theme init (must run before paint): localStorage.theme falls back to prefers-color-scheme. Plain JS only, no Livewire/Alpine. -->
        <script>
            window.ControlDeskApplyTheme = window.ControlDeskApplyTheme || function () {
                try {
                    var storedTheme = localStorage.getItem('theme');
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', storedTheme === 'dark' || (! storedTheme && prefersDark));
                } catch (e) {}
            };
            window.ControlDeskApplyTheme();
            // Livewire `wire:navigate` swaps in fresh markup and drops runtime
            // classes from <html>, so re-apply the theme after every visit.
            document.addEventListener('livewire:navigated', function () {
                window.ControlDeskApplyTheme();
            });
        </script>

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'ControlDesk') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-slate-900 dark:text-slate-100">
        <div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-slate-100 dark:bg-ink">
            <div class="absolute top-4 right-4">
                <x-theme-toggle />
            </div>

            <div>
                <a href="/" wire:navigate class="flex flex-col items-center gap-2">
                    <x-application-logo class="h-20 w-20" />
                    <span class="text-2xl font-semibold tracking-tight text-brand dark:text-white">ControlDesk</span>
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-deep text-slate-900 dark:text-slate-100 border border-slate-200 dark:border-white/10 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
