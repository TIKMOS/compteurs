<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\ConsumptionCredit;
use App\Models\CreditMovement;
use App\Models\User;
use App\Modules\Metering\Data\CreditLot;
use App\Modules\Metering\Data\UtilityAllocation;
use App\Modules\Metering\Exceptions\IncompletePeriodException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Modules\Metering\Support\Journal;
use App\Shared\Enums\ChargeKind;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Clôture d'un mois (UC-14, EF-31, EF-32, CONCEPTION.md § 3.3) : sous verrou, recalcule le mois
 * et fige le résultat — tarification et prix de chaque fluide, participations par payeur, lots
 * et mouvements de crédit, soldes des locataires sortants. La période devient non modifiable.
 */
final class CloseBillingPeriod
{
    public function __construct(private readonly ComputeBillingPeriod $compute) {}

    /**
     * @throws IncompletePeriodException|PeriodClosedException
     */
    public function execute(BillingPeriod $period, User $by): BillingPeriod
    {
        return DB::transaction(function () use ($period, $by): BillingPeriod {
            $period = BillingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->isClosed()) {
                throw new PeriodClosedException($period);
            }

            $computation = $this->compute->execute($period);
            if (! $computation->canClose()) {
                throw new IncompletePeriodException($computation->problems);
            }

            /** @var array<string, int> $lotIds référence du lot (CreditLot::reference) → id en base */
            $lotIds = [];

            foreach ($computation->allocations as $utilityValue => $allocation) {
                $utility = Utility::from($utilityValue);
                $this->freezeUtility($period, $utility, $allocation, $computation->pricings[$utilityValue]);
                $this->writeCredits($period, $utility, $allocation, $lotIds);
            }

            foreach ($computation->settlements as $occupancyId => $settlement) {
                $period->creditSettlements()->create([
                    'occupancy_id' => $occupancyId,
                    'statement_total' => $settlement->statementTotal,
                    'credit_value' => $settlement->creditValue,
                    'deducted_amount' => $settlement->deductedAmount,
                    'refund_due' => $settlement->refundDue,
                ]);
            }

            $period->forceFill([
                'status' => PeriodStatus::Closed,
                'closed_at' => CarbonImmutable::now(),
                'closed_by' => $by->id,
            ])->save();

            $totals = [];
            foreach ($computation->allocations as $utilityValue => $allocation) {
                $totals[$utilityValue] = [
                    'unit_price' => (string) $allocation->price?->applied,
                    'price_source' => $allocation->price?->source->value,
                    'participations' => $allocation->result->sum(),
                ];
            }
            Journal::record('closed', 'Mois ' . ComputeBillingPeriod::of($period->label()) . ' clôturé', $period, [
                'utilities' => $totals,
                'credit_settlements' => count($computation->settlements),
            ]);

            return $period;
        });
    }

    private function freezeUtility(BillingPeriod $period, Utility $utility, UtilityAllocation $allocation, \App\Models\UtilityPricing $pricing): void
    {
        $price = $allocation->price ?? throw new LogicException('Prix non résolu.');
        $bill = $period->bills()->firstOrNew(['utility' => $utility]);
        $bill->main_meter_id ??= $period->house->requireMainMeter($utility)->id;

        $bill->forceFill([
            'utility_pricing_id' => $pricing->id,
            'pricing_mode' => $pricing->mode->value,
            'fixed_unit_price' => $pricing->fixed_unit_price,
            'cap_to_actual' => $pricing->cap_to_actual,
            'total_consumption' => Milli::toDecimal($allocation->result->totalConsumption),
            'actual_unit_price' => $price->actual === null ? null : (string) $price->actual,
            'unit_price' => (string) $price->applied,
            'price_source' => $price->source->value,
        ])->save();

        foreach ($allocation->payers as $payer) {
            $line = $allocation->result->lines[$payer->key];
            $bill->charges()->create([
                'unit_id' => $payer->unitId,
                'occupancy_id' => $payer->occupancyId,
                'kind' => $payer->isVacant() ? ChargeKind::Vacant : ChargeKind::Tenant,
                'raw_consumption' => Milli::toDecimal($payer->raw),
                'credit_applied' => Milli::toDecimal($payer->creditApplied),
                'consumption' => Milli::toDecimal($payer->billed),
                'is_estimated' => $payer->isEstimated,
                'includes_regularization' => $payer->includesRegularization,
                'theoretical_amount' => $line->theoreticalAmount,
                'rounding_adjustment' => $line->roundingAdjustment,
                'amount' => $line->amount,
            ]);
        }
    }

    /**
     * Lots créés ce mois (surestimations révélées) et mouvements d'imputation ou de solde.
     *
     * @param array<string, int> $lotIds
     */
    private function writeCredits(BillingPeriod $period, Utility $utility, UtilityAllocation $allocation, array &$lotIds): void
    {
        foreach ($allocation->credits as $unitId => $credit) {
            foreach ($credit->createdLots as $lot) {
                $origin = BillingPeriod::query()
                    ->where('house_id', $period->house_id)
                    ->where('month', $lot->originMonth . '-01')
                    ->firstOrFail();

                $record = ConsumptionCredit::query()->create([
                    'occupancy_id' => $lot->occupancyId,
                    'unit_id' => $unitId,
                    'utility' => $utility,
                    'origin_billing_period_id' => $origin->id,
                    'created_in_billing_period_id' => $period->id,
                    'quantity' => Milli::toDecimal($lot->quantity),
                    'unit_price' => (string) $lot->unitPrice,
                ]);
                $lotIds[$lot->reference()] = $record->id;
            }

            foreach ($credit->movements as $movement) {
                (new CreditMovement)->forceFill([
                    'consumption_credit_id' => $this->lotId($movement->lot, $lotIds),
                    'billing_period_id' => $period->id,
                    'kind' => $movement->kind,
                    'quantity' => Milli::toDecimal($movement->quantity),
                    'value' => $movement->value === null ? null : (string) $movement->value,
                ])->save();
            }
        }
    }

    /**
     * @param array<string, int> $lotIds
     */
    private function lotId(CreditLot $lot, array $lotIds): int
    {
        return $lot->id ?? $lotIds[$lot->reference()] ?? throw new LogicException('Lot de crédit introuvable : ' . $lot->reference());
    }
}
