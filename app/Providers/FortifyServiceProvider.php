<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Shared\Support\BeninPhone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Fortify\Fortify;

/**
 * Fortify gère la logique d'authentification ; les écrans sont les nôtres
 * (resources/views/auth, repris d'artisandubenin), cf. docs/STACK.md.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn (): View => view('auth.login'));
        Fortify::registerView(fn (): View => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn (): View => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request): View => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn (): View => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn (): View => view('auth.confirm-password'));

        // Le champ « login » contient un email ou un numéro béninois (UC-01).
        Fortify::authenticateUsing(static function (Request $request): ?User {
            $login = trim((string) $request->input(Fortify::username()));

            $user = str_contains($login, '@')
                ? User::query()->where('email', Str::lower($login))->first()
                : User::query()->where('phone', BeninPhone::normalize($login) ?? '')->first();

            return $user !== null && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        RateLimiter::for('login', static function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())) . '|' . $request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
