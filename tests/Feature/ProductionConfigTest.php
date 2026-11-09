<?php

declare(strict_types=1);

// Revue avant déploiement (I-2) : le modèle de configuration de production est sûr.

function productionEnv(): array
{
    $values = [];
    foreach (file(base_path('.env.production.example'), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) === 1) {
            $values[$m[1]] = trim($m[2], '"');
        }
    }

    return $values;
}

test('.env.production.example : production, sans débogage, journaux quotidiens, cookies sécurisés', function (): void {
    $env = productionEnv();

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['APP_KEY'])->toBe('')
        ->and($env['APP_URL'])->toStartWith('https://')
        ->and($env['APP_LOCALE'])->toBe('fr')
        ->and($env['APP_TIMEZONE'])->toBe('Africa/Porto-Novo')
        ->and($env['LOG_STACK'])->toBe('daily')
        ->and($env['LOG_LEVEL'])->not->toBe('debug')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['SESSION_HTTP_ONLY'])->toBe('true')
        ->and($env['MAIL_MAILER'])->toBe('smtp')
        ->and($env['DB_PASSWORD'])->toBe('');
});

test('le script de déploiement : maintenance, sans paquets de dev, sauvegarde, migrations, caches', function (): void {
    $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

    expect($script)->toContain("grep -qE '^APP_DEBUG=true' .env")
        ->toContain('artisan down')
        ->toContain('install --no-dev --optimize-autoloader')
        ->toContain('artisan compteurs:sauvegarde')
        ->toContain('artisan migrate --force')
        ->toContain('artisan config:cache')
        ->toContain('artisan route:cache')
        ->toContain('artisan view:cache')
        ->toContain('artisan up')
        ->toContain('--retour-arriere')
        ->not->toContain("\r\n");

    // Ordre : maintenance → code → dépendances → sauvegarde → migrations → caches → réouverture.
    $positions = array_map(fn (string $needle): int => (int) strpos($script, $needle), [
        'artisan down', 'git reset', 'install --no-dev', 'compteurs:sauvegarde', 'migrate --force', 'config:cache', 'artisan up',
    ]);
    expect($positions)->toBe(array_values(Illuminate\Support\Arr::sort($positions)));
});

test('le pipeline GitHub Actions ne déploie que main, après les tests, sans secret en clair', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain('needs: tests')
        ->toContain("github.ref == 'refs/heads/main'")
        ->toContain('secrets.SSH_PRIVATE_KEY')
        ->toContain('scripts/deploy.sh')
        ->not->toMatch('/BEGIN [A-Z ]*PRIVATE KEY/')
        ->not->toMatch('/(PASSWORD|APP_KEY)\s*[:=]\s*\S{6,}/');
});

test('production : HTTP redirigé vers HTTPS, HSTS en HTTPS ; rien en local', function (): void {
    $this->get('http://localhost/login')->assertOk();

    app()->detectEnvironment(fn (): string => 'production');

    $this->get('http://localhost/login?x=1')
        ->assertStatus(301)
        ->assertRedirect('https://localhost/login?x=1');

    expect($this->get('https://localhost/up')->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000');
});

test('les pages d\'erreur ne dépendent ni de Vite ni de Livewire (elles marchent même en panne)', function (): void {
    $layout = (string) file_get_contents(resource_path('views/errors/layout.blade.php'));

    expect($layout)->not->toContain('@vite')->not->toContain('@livewire')->not->toContain('route(');
});
