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
        <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10 sm:py-14 bg-gradient-to-b from-sand-50 via-white to-primary-50/40">
            <a href="{{ url('/') }}" class="flex flex-col items-center gap-2" aria-label="{{ config('app.name') }} — Accueil">
                <x-brand-logo class="h-10" />
                <span class="text-[11px] font-normal text-sand-500 tracking-wider uppercase">Répartition des charges d'eau et d'électricité</span>
            </a>

            <div class="w-full max-w-md mt-8 px-6 sm:px-8 py-7 bg-white border border-sand-200 shadow-lg shadow-primary-900/5 rounded-2xl">
                {{ $slot }}
            </div>

            <footer class="mt-6 text-xs text-sand-500 text-center">
                © {{ date('Y') }} {{ config('app.name') }} ·
                <a href="{{ route('legal.terms') }}" class="hover:underline">Conditions d'utilisation</a>
            </footer>
        </div>

        {{-- Livewire et Alpine sont chargés par resources/js/app.js (bundle ESM) : on ne
             fournit ici que la configuration, comme dans artisandubenin. --}}
        @livewireScriptConfig
    </body>
</html>
