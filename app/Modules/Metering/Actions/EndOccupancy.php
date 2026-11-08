<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Occupancy;
use App\Models\Unit;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sortie d'un locataire (UC-06, EF-07, EF-08) : date de fin de l'occupation et relevé de
 * sortie sur chaque décompteur actif, dans la période du mois (EF-12). Le logement devient
 * vacant ; sa consommation jusqu'à l'entrée suivante revient au propriétaire (EF-10).
 *
 * Le solde du crédit de consommation du locataire (EF-24 f) se fait à la clôture du mois
 * de sortie (tranche 5).
 */
final class EndOccupancy
{
    public function __construct(
        private readonly OpenBillingPeriod $openBillingPeriod,
        private readonly AssertReadingFitsChain $assertReading,
    ) {}

    /**
     * @param array<value-of<Utility>, string> $indexes index décimal par fluide, « 1626.000 »
     *
     * @throws OccupancyRejectedException|ReadingRejectedException|PeriodClosedException
     */
    public function execute(Occupancy $occupancy, CarbonImmutable $endedOn, array $indexes): Occupancy
    {
        $endedOn = $endedOn->startOfDay();

        return DB::transaction(function () use ($occupancy, $endedOn, $indexes): Occupancy {
            Unit::query()->whereKey($occupancy->unit_id)->lockForUpdate()->first();
            $occupancy->refresh();
            $unit = $occupancy->unit()->with(['house', 'subMeters'])->firstOrFail();

            if (! $occupancy->isCurrent()) {
                throw new OccupancyRejectedException('Cette sortie est déjà enregistrée.');
            }

            if ($endedOn->isAfter(CarbonImmutable::today())) {
                throw new OccupancyRejectedException('La date de sortie ne peut pas être dans le futur.');
            }

            if ($endedOn->lessThan($occupancy->started_on)) {
                throw new OccupancyRejectedException(sprintf(
                    'La date de sortie ne peut pas précéder la date d\'entrée (%s).',
                    $occupancy->started_on->format('d/m/Y'),
                ));
            }

            $period = $this->openBillingPeriod->execute($unit->house, $endedOn);

            $readings = [];
            foreach (Utility::cases() as $utility) {
                $meter = $unit->activeSubMeter($utility);
                if ($meter === null) {
                    continue;
                }

                $value = $indexes[$utility->value] ?? null;
                if ($value === null || $value === '') {
                    throw new ReadingRejectedException($utility, "Saisissez l'index de sortie du décompteur d'{$utility->label()}.");
                }

                $index = Milli::fromDecimal($value);
                $this->assertReading->assert($meter, ReadingKind::Exit, $endedOn, $index);
                $readings[] = [$meter, $index];
            }

            $occupancy->update(['ended_on' => $endedOn]);

            foreach ($readings as [$meter, $index]) {
                $meter->readings()->create([
                    'billing_period_id' => $period->id,
                    'occupancy_id' => $occupancy->id,
                    'kind' => ReadingKind::Exit,
                    'read_on' => $endedOn,
                    'index_value' => Milli::toDecimal($index),
                ]);
            }

            return $occupancy;
        });
    }
}
