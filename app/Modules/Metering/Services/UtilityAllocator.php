<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\CreditLot;
use App\Modules\Metering\Data\OccupancySpan;
use App\Modules\Metering\Data\PayerConsumption;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Data\Segment;
use App\Modules\Metering\Data\UtilityAllocation;
use App\Shared\Enums\ReadingKind;

/**
 * Chaîne de calcul d'un fluide pour une période, sans base de données :
 * tranches (EF-11) → crédit du locataire (EF-24) → regroupement par payeur → répartition (EF-26 à EF-29).
 * L'Action CloseBillingPeriod charge les données, appelle ce service et fige le résultat.
 */
final class UtilityAllocator
{
    public function __construct(
        private readonly SegmentBuilder $segments = new SegmentBuilder,
        private readonly CreditLedger $ledger = new CreditLedger,
        private readonly ChargeAggregator $aggregator = new ChargeAggregator,
        private readonly AllocationCalculator $calculator = new AllocationCalculator,
    ) {}

    /**
     * @param list<array{unitId: int, unitLabel: string, occupancies: list<OccupancySpan>, subMeters: list<array{id: int, readings: list<Reading>}>, creditLots?: array<string, list<CreditLot>>}> $units
     */
    public function allocate(int $totalAmount, array $units): UtilityAllocation
    {
        $credits = [];
        $billedByUnit = [];
        $startedOn = [];

        foreach ($units as $unit) {
            foreach ($unit['occupancies'] as $occupancy) {
                $startedOn[$occupancy->id] = $occupancy->startedOn->format('Y-m-d');
            }

            $segments = [];
            foreach ($unit['subMeters'] as $subMeter) {
                array_push($segments, ...$this->segments->build($subMeter['readings'], $unit['occupancies']));
            }

            $credit = $this->ledger->apply($segments, $unit['creditLots'] ?? [], $this->exitingOccupancies($segments));
            $credits[$unit['unitId']] = $credit;
            $billedByUnit[] = ['unitId' => $unit['unitId'], 'unitLabel' => $unit['unitLabel'], 'segments' => $credit->segments];
        }

        $payers = $this->aggregator->aggregate($billedByUnit, $startedOn);
        $result = $this->calculator->allocate(
            $totalAmount,
            array_map(static fn (PayerConsumption $payer) => $payer->toAllocationLine(), $payers),
        );

        return new UtilityAllocation($payers, $result, $credits);
    }

    /**
     * @param list<Segment> $segments
     *
     * @return list<int>
     */
    private function exitingOccupancies(array $segments): array
    {
        $ids = [];
        foreach ($segments as $segment) {
            if ($segment->end->kind === ReadingKind::Exit && $segment->end->occupancyId !== null) {
                $ids[] = $segment->end->occupancyId;
            }
        }

        return array_values(array_unique($ids));
    }
}
