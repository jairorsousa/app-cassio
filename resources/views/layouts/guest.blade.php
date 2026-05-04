<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cassio Finance') }}</title>

        <script>
            if (localStorage.getItem('jr-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-mono-50 font-sans text-mono-900 antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center px-4 py-8">
            <div class="mb-8 flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-500 text-base font-bold text-white shadow-card">CM</span>
                <span class="text-xl font-bold text-mono-900">Cassio Finance</span>
            </div>

            <div class="w-full max-w-md rounded-2xl border border-mono-100 bg-mono-white px-8 py-8 shadow-card">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
