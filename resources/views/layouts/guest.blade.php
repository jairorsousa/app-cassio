<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cassio Finance') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-mono-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-mono-50">
            <div class="flex items-center gap-xs mb-md">
                <span class="inline-block w-12 h-12 rounded-full bg-primary-500"></span>
                <span class="font-bold text-xl text-mono-900">Cassio Finance</span>
            </div>

            <div class="w-full sm:max-w-md mt-md px-lg py-lg bg-mono-white shadow-elevated rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
