{{--
    Bare layout for the shared-trade page (issue #18): no app navigation and
    no links into any journal. Plain HTML only — no Livewire — so a revoked
    viewer's open tab holds no component state that could act on the trade.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ $title ?? 'Shared trade' }} · {{ config('app.name', 'Chart Jot') }}</title>
        <script>
            (function () {
                const stored = localStorage.getItem('theme');
                const dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">
        <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
                <span class="font-semibold">{{ config('app.name', 'Chart Jot') }} <span class="text-gray-500 dark:text-gray-400 font-normal">· Shared trade</span></span>
                <span class="text-xs text-gray-500 dark:text-gray-400">Signed in as {{ auth()->user()->name }}</span>
            </div>
        </header>
        <main class="max-w-3xl mx-auto px-4 sm:px-6 py-6">
            {{ $slot }}
        </main>
    </body>
</html>
