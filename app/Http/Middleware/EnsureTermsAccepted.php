<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\AcceptTerms;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un propriétaire qui n'a pas accepté la version en vigueur des conditions d'utilisation est
 * renvoyé vers la page d'acceptation avant tout accès (EF-51, H-36).
 */
final class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! AcceptTerms::isUpToDate($user)) {
            return redirect()->guest(route('legal.accept'));
        }

        return $next($request);
    }
}
