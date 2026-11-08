<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Unit;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Support\Journal;

/**
 * Archive un logement (UC-03) : il disparaît de la maison, ses décompteurs et leur
 * historique restent en base. Refusé tant qu'un locataire l'occupe.
 */
final class ArchiveUnit
{
    /**
     * @throws OccupancyRejectedException
     */
    public function execute(Unit $unit): void
    {
        if ($unit->occupancies()->whereNull('ended_on')->exists()) {
            throw new OccupancyRejectedException("{$unit->label} est occupé : enregistrez d'abord la sortie du locataire.");
        }

        Journal::record('archived', "Logement « {$unit->label} » archivé", $unit);
        $unit->delete();
    }
}
