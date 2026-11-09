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

test('le script de mise en production refuse APP_DEBUG=true et met les caches', function (): void {
    $script = (string) file_get_contents(base_path('scripts/post-deploy.sh'));

    expect($script)->toContain("grep -qE '^APP_DEBUG=true' .env")
        ->toContain('php artisan migrate --force')
        ->toContain('php artisan config:cache')
        ->toContain('php artisan route:cache')
        ->not->toContain("\r\n");
});

test('les pages d\'erreur ne dépendent ni de Vite ni de Livewire (elles marchent même en panne)', function (): void {
    $layout = (string) file_get_contents(resource_path('views/errors/layout.blade.php'));

    expect($layout)->not->toContain('@vite')->not->toContain('@livewire')->not->toContain('route(');
});
