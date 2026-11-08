<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#1f2a8a">

        <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @include('partials.color-mode-boot')

        @livewireStyles

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-sand-900 antialiased bg-sand-50">
        <header class="sticky top-0 z-10 bg-white/95 border-b border-sand-200 backdrop-blur">
            <div class="mx-auto max-w-3xl h-14 px-4 flex items-center justify-between">
                <a href="{{ route('maisons.index') }}" class="h-7" aria-label="{{ config('app.name') }} — Mes maisons">
                    <x-brand-logo class="h-7" />
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm">Se déconnecter</x-ui.button>
                </form>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            {{ $slot }}
        </main>

        @livewireScriptConfig
    </body>
</html>
