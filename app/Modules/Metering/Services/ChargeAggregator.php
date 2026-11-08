<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\BilledSegment;
use App\Modules\Metering\Data\PayerConsumption;

/**
 * Regroupe les tranches facturées par payeur et par logement. Classe pure.
 * Une tranche vacante de consommation nulle (passation le même jour) ne produit pas de ligne.
 */
final class ChargeAggregator
{
    /**
     * @param list<array{unitId: int, unitLabel: string, segments: list<BilledSegment>}> $units
     * @param array<int, string> $occupancyStartedOn date d'entrée (Y-m-d) par occupation
     *
     * @return list<PayerConsumption>
     */
    public function aggregate(array $units, array $occupancyStartedOn): array
    {
        /** @var array<string, array{unitId: int, unitLabel: string, occupancyId: ?int, raw: int, credit: int, billed: int, estimated: bool, regularization: bool}> $rows */
        $rows = [];

        foreach ($units as $unit) {
            foreach ($unit['segments'] as $billed) {
                $occupancyId = $billed->segment->occupancyId;
                $key = PayerConsumption::keyFor($unit['unitId'], $occupancyId);

                $rows[$key] ??= [
                    'unitId' => $unit['unitId'],
                    'unitLabel' => $unit['unitLabel'],
                    'occupancyId' => $occupancyId,
                    'raw' => 0,
                    'credit' => 0,
                    'billed' => 0,
                    'estimated' => false,
                    'regularization' => false,
                ];
                $rows[$key]['raw'] += $billed->raw();
                $rows[$key]['credit'] += $billed->creditApplied;
                $rows[$key]['billed'] += $billed->billed;
                $rows[$key]['estimated'] = $rows[$key]['estimated'] || $billed->segment->isEstimated();
                $rows[$key]['regularization'] = $rows[$key]['regularization'] || $billed->segment->isRegularization();
            }
        }

        $result = [];
        foreach ($rows as $key => $row) {
            if ($row['occupancyId'] === null && $row['raw'] === 0 && $row['billed'] === 0) {
                continue;
            }

            $result[] = new PayerConsumption(
                key: $key,
                unitId: $row['unitId'],
                unitLabel: $row['unitLabel'],
                occupancyId: $row['occupancyId'],
                startedOn: $row['occupancyId'] === null ? null : ($occupancyStartedOn[$row['occupancyId']] ?? null),
                raw: $row['raw'],
                creditApplied: $row['credit'],
                billed: $row['billed'],
                isEstimated: $row['estimated'],
                includesRegularization: $row['regularization'],
            );
        }

        return $result;
    }
}
