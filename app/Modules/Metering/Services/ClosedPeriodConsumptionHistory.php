<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Models\Unit;
use App\Modules\Metering\Contracts\BilledConsumptionHistory;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;

/**
 * Historique lu dans les participations figées des périodes clôturées (table charges).
 *
 * La clôture et la table charges arrivent avec la tranche 5 : d'ici là, aucune période n'est
 * clôturée et l'historique est vide (l'estimation répond « Pas d'historique… », EF-25).
 */
final class ClosedPeriodConsumptionHistory implements BilledConsumptionHistory
{
    public function forUnit(Unit $unit, Utility $utility, CarbonImmutable $beforeMonth): array
    {
        return [];
    }
}
