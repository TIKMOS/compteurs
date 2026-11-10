<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\SupervisionOverview;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panneau d'administration /admin (UC-21, H-79) : supervision en lecture seule des comptes, des
 * maisons, des confirmations de conformité (EF-49) et du journal d'activité (EF-35).
 *
 * Pas de page de connexion propre : un visiteur non connecté est renvoyé vers /login (Fortify) ;
 * un compte connecté qui n'est pas administrateur reçoit une erreur 403
 * (User::canAccessPanel).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name') . ' — Administration')
            ->colors(['primary' => Color::Emerald])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->widgets([SupervisionOverview::class, AccountWidget::class])
            ->pages([Dashboard::class])
            ->userMenuItems([
                Action::make('application')
                    ->label('Retour à l\'application')
                    ->icon(Heroicon::OutlinedArrowLeft)
                    ->url(fn (): string => route('maisons.index')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
