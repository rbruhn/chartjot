<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Apply the saved theme before first paint, to avoid a flash of the wrong theme.
             Re-applied on livewire:navigated too, since wire:navigate swaps the page
             without re-running this script otherwise (each new page would silently
             lose the dark class). Also syncs the Alpine store (registered below) so
             every component reading it — e.g. the theme toggle buttons — reflects
             the true state instead of a per-component local copy that can go stale. -->
        <script>
            function applyTheme() {
                const stored = localStorage.getItem('theme');
                const dark = stored
                    ? stored === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
                if (window.Alpine?.store('theme')) {
                    window.Alpine.store('theme').dark = dark;
                }
            }
            applyTheme();
            document.addEventListener('livewire:navigated', applyTheme);
            document.addEventListener('alpine:init', () => {
                Alpine.store('theme', {
                    dark: document.documentElement.classList.contains('dark'),
                    set(value) {
                        this.dark = value;
                        document.documentElement.classList.toggle('dark', value);
                        localStorage.setItem('theme', value ? 'dark' : 'light');
                    },
                });
            });
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-800">
            <livewire:layout.navigation />

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
    </body>
</html>
