<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\CreditMovement;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Modules\Metering\Data\ExitStatement;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Shared\Enums\CreditMovementKind;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;

/**
 * Fiche de sortie d'un locataire (D-11, D-14, D-15) : derniers index, crédit restant converti
 * en FCFA lot par lot au prix du mois surestimé, somme arrondie une seule fois, déduction de
 * la dernière fiche et montant « à rembourser au locataire ». Disponible une fois le mois de
 * sortie clôturé (les valeurs sont alors figées) ; null avant.
 */
final class BuildExitStatement
{
    public function execute(Occupancy $occupancy): ?ExitStatement
    {
        if ($occupancy->ended_on === null) {
            return null;
        }

        $occupancy->loadMissing(['tenant', 'unit.house']);
        $house = $occupancy->unit->house;

        $period = BillingPeriod::query()
            ->where('house_id', $house->id)
            ->where('month', $occupancy->ended_on->startOfMonth()->toDateString())
            ->first();
        if ($period === null || ! $period->isClosed()) {
            return null;
        }

        $readings = MeterReading::query()
            ->where('occupancy_id', $occupancy->id)
            ->where('kind', ReadingKind::Exit)
            ->with('subMeter')
            ->get()
            ->sortBy(fn (MeterReading $r): int => $r->subMeter->utility === \App\Shared\Enums\Utility::Electricity ? 0 : 1)
            ->map(fn (MeterReading $r): array => [
                'utility' => $r->subMeter->utility,
                'index' => Milli::fromDecimal($r->index_value),
                'readOn' => $r->read_on,
                'estimated' => $r->is_estimated,
            ])
            ->values()
            ->all();

        $lots = CreditMovement::query()
            ->where('billing_period_id', $period->id)
            ->where('kind', CreditMovementKind::Settled)
            ->whereHas('credit', fn ($q) => $q->where('occupancy_id', $occupancy->id))
            ->with('credit.originPeriod')
            ->get()
            ->map(fn (CreditMovement $m): array => [
                'utility' => $m->credit->utility,
                'quantity' => Milli::fromDecimal($m->quantity),
                'unitPrice' => ResolvedPrice::format(Fraction::parse($m->credit->unit_price)),
                'origin' => $m->credit->originPeriod->label(),
                'value' => ResolvedPrice::format(Fraction::parse((string) $m->value)),
            ])
            ->values()
            ->all();

        $settlement = $period->creditSettlements()->where('occupancy_id', $occupancy->id)->first();

        return new ExitStatement(
            $period,
            $house,
            $occupancy->unit,
            $occupancy,
            $readings,
            $lots,
            creditValue: $settlement->credit_value ?? 0,
            lastStatementTotal: TenantBalance::due($occupancy, $period) + ($settlement->deducted_amount ?? 0),
            deducted: $settlement->deducted_amount ?? 0,
            refundDue: $settlement->refund_due ?? 0,
            paid: TenantBalance::paid($occupancy, $period),
        );
    }
}
