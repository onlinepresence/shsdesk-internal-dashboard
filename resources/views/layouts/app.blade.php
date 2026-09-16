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
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <livewire:layout.navigation />

            <!-- Offset for the fixed desktop sidebar; existing pages render untouched below. -->
            <div class="lg:pl-64">
                <!-- Page Heading -->
                @if (isset($header))
                    <header class="bg-white dark:bg-gray-800 shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <!-- Page Content -->
                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
