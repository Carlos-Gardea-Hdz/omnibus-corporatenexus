<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'CorporateNexus') }}</title>

        {{-- No-flash theme: apply the persisted/system colour scheme before paint. --}}
        <script nonce="{{ request()->attributes->get('csp_nonce') }}">
            (function () {
                try {
                    var stored = localStorage.getItem('theme');
                    var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (stored === 'dark' || (stored !== 'light' && system)) {
                        document.documentElement.classList.add('dark');
                    }
                } catch (e) {}
            })();
        </script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="h-full bg-white text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        @inertia
    </body>
</html>
