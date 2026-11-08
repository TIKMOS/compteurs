<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Models\Charge;
use App\Models\Unit;
use App\Modules\Metering\Contracts\BilledConsumptionHistory;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;

/**
 * Consommations facturées d'un logement, estimées comprises (D-8), lues dans les
 * participations figées des 3 dernières périodes clôturées avant le mois estimé (EF-22).
 */
final class ClosedPeriodConsumptionHistory implements BilledConsumptionHistory
{
    public function forUnit(Unit $unit, Utility $utility, CarbonImmutable $beforeMonth): array
    {
        $charges = Charge::query()
            ->where('unit_id', $unit->id)
            ->whereHas('utilityBill', fn ($q) => $q->where('utility', $utility)
                ->whereHas('billingPeriod', fn ($p) => $p->where('status', PeriodStatus::Closed)
                    ->where('month', '<', $beforeMonth->startOfMonth()->toDateString())))
            ->with('utilityBill.billingPeriod')
            ->get();

        $byMonth = [];
        foreach ($charges as $charge) {
            $month = $charge->utilityBill->billingPeriod->month->format('Y-m');
            $byMonth[$month] = ($byMonth[$month] ?? 0) + Milli::fromDecimal($charge->consumption);
        }

        ksort($byMonth);

        return array_values(array_slice($byMonth, -ReadingEstimator::HISTORY_MONTHS));
    }
}
