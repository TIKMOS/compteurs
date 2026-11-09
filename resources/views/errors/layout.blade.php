{{--
    Pages d'erreur (revue I-1). Autonomes : styles en ligne, ni Vite, ni Livewire, ni base de
    données, pour s'afficher même quand l'application est en panne (500, 503).
--}}
<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex">
        <meta name="theme-color" content="#1f2a8a">
        <title>@yield('title') · {{ config('app.name', 'Compteurs') }}</title>
        <style>
            :root { color-scheme: light; --ink: #1d1b18; --muted: #5d5850; --line: #e6e1d8; --bg: #faf8f4; --primary: #2a3b9c; }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
                font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); }
            main { width: 100%; max-width: 420px; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; text-align: center;
                box-shadow: 0 10px 30px rgba(31, 42, 138, .06); }
            .code { font-size: 13px; font-weight: 600; letter-spacing: .08em; color: var(--muted); }
            h1 { margin: 8px 0 10px; font-size: 21px; line-height: 1.3; }
            p { margin: 0 0 12px; font-size: 15px; line-height: 1.5; color: var(--muted); }
            .actions { margin-top: 20px; display: flex; flex-direction: column; gap: 10px; }
            a.button { display: block; min-height: 44px; padding: 11px 16px; border-radius: 10px; font-weight: 600; font-size: 15px; text-decoration: none; }
            a.primary { background: var(--primary); color: #fff; }
            a.secondary { border: 1px solid var(--line); color: var(--ink); }
            footer { margin-top: 18px; font-size: 12px; color: var(--muted); }
        </style>
    </head>
    <body>
        <main>
            <div class="code">ERREUR @yield('code')</div>
            <h1>@yield('title')</h1>
            @yield('message')
            <div class="actions">
                @section('actions')
                    <a class="button primary" href="{{ url('/maisons') }}">Retour à mes maisons</a>
                    <a class="button secondary" href="javascript:history.back()">Page précédente</a>
                @show
            </div>
            <footer>{{ config('app.name', 'Compteurs') }} · répartition des charges d'eau et d'électricité</footer>
        </main>
    </body>
</html>
