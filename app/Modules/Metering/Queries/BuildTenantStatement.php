<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\ConsumptionCredit;
use App\Models\Occupancy;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Modules\Metering\Data\TenantStatement;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use LogicException;

/**
 * Construit la fiche d'un locataire pour un mois clôturé à partir des valeurs figées
 * (participations, prix) et des relevés du mois (index de chaque tranche, EF-36).
 */
final class BuildTenantStatement
{
    public function __construct(private readonly ComputeBillingPeriod $compute) {}

    /**
     * « 1 392 le 31/10 (fin de mois, estimé) ».
     */
    private static function describe(Reading $reading): string
    {
        $kind = match ($reading->kind) {
            ReadingKind::Initial, ReadingKind::Installation => 'pose',
            ReadingKind::Entry => 'entrée',
            ReadingKind::Exit => 'sortie',
            ReadingKind::Removal => 'dépose',
            ReadingKind::MonthEnd => 'fin de mois',
        };

        return sprintf('%s le %s (%s%s)', Milli::format($reading->index), $reading->readOn->format('d/m'), $kind, $reading->isEstimated ? ', estimé' : '');
    }

    public function execute(BillingPeriod $period, Occupancy $occupancy): TenantStatement
    {
        if (! $period->isClosed()) {
            throw new LogicException('La fiche n\'existe que pour un mois clôturé.');
        }

        // load() et non loadMissing() : des factures chargées avant la clôture n'ont pas les prix figés.
        $period->load(['house', 'bills', 'creditSettlements']);
        $occupancy->loadMissing(['tenant', 'unit']);
        $unit = $occupancy->unit;

        $utilities = [];
        foreach (Utility::cases() as $utility) {
            $bill = $period->bill($utility);
            $charge = $bill === null ? null : Charge::query()
                ->where('utility_bill_id', $bill->id)
                ->where('occupancy_id', $occupancy->id)
                ->first();
            if ($charge === null) {
                continue;
            }

            $segments = array_values(array_filter(
                $this->compute->segmentsFor($unit, $utility, $period),
                fn ($segment): bool => $segment->occupancyId === $occupancy->id,
            ));

            $estimatedEnd = collect($segments)->first(fn ($s): bool => $s->end->isEstimated);

            $utilities[] = [
                'utility' => $utility,
                'priceLabel' => ResolvedPrice::fromBill($bill)?->label($utility->unit()) ?? '',
                'segments' => array_map(fn ($s): array => [
                    'from' => self::describe($s->start),
                    'to' => self::describe($s->end),
                    'consumption' => $s->rawConsumption(),
                ], $segments),
                'raw' => Milli::fromDecimal($charge->raw_consumption),
                'creditApplied' => Milli::fromDecimal($charge->credit_applied),
                'consumption' => Milli::fromDecimal($charge->consumption),
                'creditBalance' => $this->creditBalance($occupancy, $utility),
                'amount' => $charge->amount,
                'estimated' => $charge->is_estimated,
                'regularization' => $charge->includes_regularization,
                'estimationBasis' => $estimatedEnd === null ? null : $this->estimationBasis($period, $unit->id, $utility),
            ];
        }

        $settlement = $period->creditSettlements->firstWhere('occupancy_id', $occupancy->id);
        $from = $occupancy->started_on->greaterThan($period->month) ? $occupancy->started_on : $period->month;
        $to = $occupancy->ended_on !== null && $occupancy->ended_on->lessThan($period->monthEnd()) ? $occupancy->ended_on : $period->monthEnd();

        return new TenantStatement(
            $period,
            $period->house,
            $unit,
            $occupancy,
            $from,
            $to,
            $utilities,
            array_sum(array_column($utilities, 'amount')),
            $settlement?->deducted_amount,
            $settlement?->refund_due,
        );
    }

    private function creditBalance(Occupancy $occupancy, Utility $utility): int
    {
        return ConsumptionCredit::query()
            ->where('occupancy_id', $occupancy->id)
            ->where('unit_id', $occupancy->unit_id)
            ->where('utility', $utility)
            ->with('movements')
            ->get()
            ->sum(fn (ConsumptionCredit $lot): int => $lot->remaining());
    }

    private function estimationBasis(BillingPeriod $period, int $unitId, Utility $utility): ?string
    {
        return $period->readings()
            ->where('kind', ReadingKind::MonthEnd)
            ->where('is_estimated', true)
            ->whereHas('subMeter', fn ($q) => $q->where('unit_id', $unitId)->where('utility', $utility))
            ->value('estimation_basis');
    }
}
