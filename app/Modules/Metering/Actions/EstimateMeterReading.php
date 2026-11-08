<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\MeterReading;
use App\Models\SubMeter;
use App\Modules\Metering\Contracts\BilledConsumptionHistory;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Exceptions\EstimationImpossibleException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Modules\Metering\Services\ReadingEstimator;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Milli;
use Illuminate\Support\Facades\DB;

/**
 * Estime l'index de fin de mois d'un décompteur non relevé (UC-11, EF-22, EF-23, EF-25) :
 * dernier index connu + moyenne des consommations facturées des 3 dernières périodes
 * clôturées, ramenée aux jours restants si le dernier index date du mois (D-7).
 */
final class EstimateMeterReading
{
    public function __construct(
        private readonly ReadingEstimator $estimator,
        private readonly BilledConsumptionHistory $history,
        private readonly AssertReadingFitsChain $assertReading,
    ) {}

    /**
     * @throws PeriodClosedException|EstimationImpossibleException|ReadingRejectedException
     */
    public function execute(BillingPeriod $period, SubMeter $meter): MeterReading
    {
        return DB::transaction(function () use ($period, $meter): MeterReading {
            $period = BillingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            RecordMonthEndReading::assertWritable($period, $meter);

            $existing = $meter->readings()
                ->where('billing_period_id', $period->id)
                ->where('kind', ReadingKind::MonthEnd)
                ->first();

            if ($existing !== null && ! $existing->is_estimated) {
                throw new ReadingRejectedException($meter->utility, 'Un index réel est déjà saisi pour ce mois : effacez-le avant de demander une estimation.');
            }

            $monthEnd = $period->monthEnd();
            $endKey = (new Reading(ReadingKind::MonthEnd, $monthEnd, 0))->sortKey();
            $previous = null;
            foreach ($meter->readingChain($existing?->id) as $reading) {
                if (strcmp($reading->sortKey(), $endKey) < 0) {
                    $previous = $reading;
                }
            }

            $history = $this->history->forUnit($meter->unit()->withTrashed()->firstOrFail(), $meter->utility, $period->month);
            $estimated = $this->estimator->estimate($previous->index, $previous->readOn, $monthEnd, $history);
            $this->assertReading->assert($meter, ReadingKind::MonthEnd, $monthEnd, $estimated, $existing?->id);

            $attributes = [
                'index_value' => Milli::toDecimal($estimated),
                'is_estimated' => true,
                'estimation_basis' => $this->estimator->basis($history),
            ];

            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }

            return $meter->readings()->create([
                ...$attributes,
                'billing_period_id' => $period->id,
                'kind' => ReadingKind::MonthEnd,
                'read_on' => $monthEnd,
            ]);
        });
    }
}
