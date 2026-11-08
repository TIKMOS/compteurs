<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Tenant;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;

/**
 * Archive un locataire (UC-04) : refusé tant qu'il occupe un logement ; son historique
 * d'occupations reste en base.
 */
final class ArchiveTenant
{
    /**
     * @throws OccupancyRejectedException
     */
    public function execute(Tenant $tenant): void
    {
        if ($tenant->hasCurrentOccupancy()) {
            throw new OccupancyRejectedException("{$tenant->name} occupe encore un logement : enregistrez d'abord sa sortie.");
        }

        $tenant->delete();
    }
}
