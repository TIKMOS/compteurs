<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Commandes des modules (app/Modules/*/Console).
    ->withCommands([__DIR__ . '/../app/Modules/Metering/Console'])
    ->withMiddleware(function (Middleware $middleware): void {
        // Production : HTTP → HTTPS + HSTS (docs/DEPLOIEMENT.md § 4).
        $middleware->prepend(App\Http\Middleware\ForceHttps::class);

        $middleware->alias([
            'terms' => App\Http\Middleware\EnsureTermsAccepted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
