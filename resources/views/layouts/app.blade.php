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

            <nav class="mx-auto max-w-3xl px-4 flex gap-1" aria-label="Navigation principale">
                @foreach (['maisons.index' => ['Maisons', 'maisons.*'], 'locataires.index' => ['Locataires', 'locataires.*']] as $routeName => [$text, $pattern])
                    @php
                        $active = request()->routeIs($pattern) || ($pattern === 'maisons.*' && request()->routeIs('logements.*'));
                    @endphp
                    <a href="{{ route($routeName) }}" wire:navigate
                        @if ($active) aria-current="page" @endif
                        class="px-3 py-2.5 text-sm font-medium border-b-2 {{ $active ? 'border-primary-600 text-primary-700' : 'border-transparent text-sand-600 hover:text-sand-900' }}">
                        {{ $text }}
                    </a>
                @endforeach
            </nav>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            @if (session('status'))
                <div class="mb-4 rounded-[var(--radius)] border border-success-border bg-success-bg px-3 py-2 text-sm text-success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-3xl px-4 pb-8 text-xs text-sand-500">
            <a href="{{ route('legal.terms') }}" class="hover:underline">Conditions d'utilisation</a>
        </footer>

        @livewireScriptConfig
    </body>
</html>
