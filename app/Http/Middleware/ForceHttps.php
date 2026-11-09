<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production : tout passe en HTTPS (certificat Let's Encrypt), avec HSTS. Doublon volontaire de la
 * règle de public/.htaccess, pour le cas où l'hébergeur ne l'appliquerait pas (docs/DEPLOIEMENT.md).
 *
 * X-Forwarded-Proto est lu directement plutôt que de faire confiance à tous les proxys
 * (trustProxies('*') laisserait falsifier l'adresse IP, utilisée par la limitation des tentatives
 * de connexion et l'avertissement du mode 2) : falsifier le schéma ne nuit qu'à celui qui le fait.
 */
final class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction()) {
            return $next($request);
        }

        $secure = $request->secure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';
        if (! $secure) {
            return new RedirectResponse('https://' . $request->getHttpHost() . $request->getRequestUri(), 301);
        }

        $response = $next($request);
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000');

        return $response;
    }
}
