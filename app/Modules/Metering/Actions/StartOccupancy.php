<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Occupancy;
use App\Models\Tenant;
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
 * Entrée d'un locataire (UC-05, EF-07, EF-08) : crée l'occupation et un relevé d'entrée par
 * décompteur actif du logement, dans la période du mois (ouverte si besoin, EF-12).
 *
 * Règles : logement vacant ; date ni future, ni antérieure à la dernière sortie (la passation
 * le même jour est permise, T-05) ; index cohérent avec la chaîne des relevés (EF-17).
 */
final class StartOccupancy
{
    public function __construct(
        private readonly OpenBillingPeriod $openBillingPeriod,
        private readonly AssertReadingFitsChain $assertReading,
    ) {}

    /**
     * @param array<value-of<Utility>, string> $indexes index décimal par fluide, « 1250.000 »
     *
     * @throws OccupancyRejectedException|ReadingRejectedException|PeriodClosedException
     */
    public function execute(Unit $unit, Tenant $tenant, CarbonImmutable $startedOn, array $indexes): Occupancy
    {
        $startedOn = $startedOn->startOfDay();

        return DB::transaction(function () use ($unit, $tenant, $startedOn, $indexes): Occupancy {
            // Verrou du logement : deux entrées simultanées ne créent pas deux occupations.
            Unit::query()->whereKey($unit->id)->lockForUpdate()->first();
            $unit->load(['house', 'occupancies.tenant', 'subMeters']);

            if ($tenant->user_id !== $unit->house->user_id) {
                throw new OccupancyRejectedException('Ce locataire n\'appartient pas à vos locataires.');
            }

            if ($startedOn->isAfter(CarbonImmutable::today())) {
                throw new OccupancyRejectedException('La date d\'entrée ne peut pas être dans le futur.');
            }

            $current = $unit->currentOccupancy();
            if ($current !== null) {
                throw new OccupancyRejectedException(sprintf(
                    '%s est occupé par %s depuis le %s : enregistrez d\'abord sa sortie.',
                    $unit->label,
                    $current->tenant->name,
                    $current->started_on->format('d/m/Y'),
                ));
            }

            $lastExit = $unit->vacantSince();
            if ($lastExit !== null && $startedOn->lessThan($lastExit)) {
                throw new OccupancyRejectedException(sprintf(
                    'La date d\'entrée ne peut pas précéder la dernière sortie (%s).',
                    $lastExit->format('d/m/Y'),
                ));
            }

            $period = $this->openBillingPeriod->execute($unit->house, $startedOn);
            $readings = $this->checkedReadings($unit, $startedOn, $indexes);

            $occupancy = $unit->occupancies()->create([
                'tenant_id' => $tenant->id,
                'started_on' => $startedOn,
            ]);

            foreach ($readings as [$meter, $index]) {
                $meter->readings()->create([
                    'billing_period_id' => $period->id,
                    'occupancy_id' => $occupancy->id,
                    'kind' => ReadingKind::Entry,
                    'read_on' => $startedOn,
                    'index_value' => Milli::toDecimal($index),
                ]);
            }

            $unit->unsetRelation('occupancies');

            return $occupancy;
        });
    }

    /**
     * @param array<value-of<Utility>, string> $indexes
     *
     * @return list<array{0: \App\Models\SubMeter, 1: int}>
     */
    private function checkedReadings(Unit $unit, CarbonImmutable $readOn, array $indexes): array
    {
        $readings = [];

        foreach (Utility::cases() as $utility) {
            $meter = $unit->activeSubMeter($utility);
            if ($meter === null) {
                continue;
            }

            $value = $indexes[$utility->value] ?? null;
            if ($value === null || $value === '') {
                throw new ReadingRejectedException($utility, "Saisissez l'index d'entrée du décompteur d'{$utility->label()}.");
            }

            $index = Milli::fromDecimal($value);
            $this->assertReading->assert($meter, ReadingKind::Entry, $readOn, $index);
            $readings[] = [$meter, $index];
        }

        return $readings;
    }
}
