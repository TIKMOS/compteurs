<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Unit;

/**
 * Archive un logement (UC-03) : il disparaît de la maison, ses décompteurs et leur
 * historique restent en base. Les locataires arrivent en tranche 3 : on y ajoutera le
 * refus d'archiver un logement occupé.
 */
final class ArchiveUnit
{
    public function execute(Unit $unit): void
    {
        $unit->delete();
    }
}
