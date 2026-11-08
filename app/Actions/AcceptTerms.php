<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Modules\Metering\Support\Journal;
use Carbon\CarbonImmutable;

/**
 * Enregistre l'acceptation de la version en vigueur des conditions d'utilisation (EF-51).
 */
final class AcceptTerms
{
    public static function currentVersion(): string
    {
        return (string) config('legal.terms_version');
    }

    public static function isUpToDate(User $user): bool
    {
        return $user->terms_version === self::currentVersion();
    }

    public function execute(User $user): void
    {
        $user->forceFill([
            'terms_version' => self::currentVersion(),
            'terms_accepted_at' => CarbonImmutable::now(),
        ])->save();

        Journal::record('terms_accepted', 'Conditions d\'utilisation acceptées (version ' . self::currentVersion() . ')', $user);
    }
}
