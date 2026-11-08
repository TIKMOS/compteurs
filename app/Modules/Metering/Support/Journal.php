<?php

declare(strict_types=1);

namespace App\Modules\Metering\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'activité (EF-35, ENF-10) : création, modification, estimation, clôture… L'auteur
 * est l'utilisateur connecté (résolu par spatie/laravel-activitylog), aucun pour le seeder.
 */
final class Journal
{
    public const LOG = 'compteurs';

    /**
     * @param array<string, mixed> $properties valeurs utiles pour comprendre l'action plus tard
     */
    public static function record(string $event, string $description, Model $subject, array $properties = []): void
    {
        activity(self::LOG)
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }
}
