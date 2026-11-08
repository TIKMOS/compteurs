<?php

declare(strict_types=1);

namespace App\Modules\Metering\Contracts;

use App\Models\Unit;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;

/**
 * Consommations facturées d'un logement pour un fluide sur les périodes clôturées, base de
 * l'estimation d'un relevé manquant (EF-22, D-8).
 */
interface BilledConsumptionHistory
{
    /**
     * @return list<int> millièmes, de la période la plus ancienne à la plus récente, avant $beforeMonth
     */
    public function forUnit(Unit $unit, Utility $utility, CarbonImmutable $beforeMonth): array;
}
