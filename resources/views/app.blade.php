<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Domain Checker') }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="alternate icon" href="/favicon.ico" type="image/x-icon">

        <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
            // Immediately apply theme before first paint to avoid flash
            (function() {
                const theme = localStorage.getItem('theme') || 'auto';
                if (theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        @routes(nonce: Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-canvas">
        @inertia
    </body>
</html>
