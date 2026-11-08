<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\Occupancy;

/**
 * Montant dû par un locataire pour un mois clôturé (UC-18) : participations du mois moins le
 * crédit remboursé à sa sortie (EF-24 f) ; reste = dû − paiements reçus.
 */
final class TenantBalance
{
    public static function due(Occupancy $occupancy, BillingPeriod $period): int
    {
        $charges = (int) Charge::query()
            ->where('occupancy_id', $occupancy->id)
            ->whereHas('utilityBill', fn ($q) => $q->where('billing_period_id', $period->id))
            ->sum('amount');

        $deducted = (int) $period->creditSettlements()->where('occupancy_id', $occupancy->id)->value('deducted_amount');

        return $charges - $deducted;
    }

    public static function paid(Occupancy $occupancy, BillingPeriod $period): int
    {
        return (int) $period->payments()->where('occupancy_id', $occupancy->id)->sum('amount');
    }

    public static function remaining(Occupancy $occupancy, BillingPeriod $period): int
    {
        return self::due($occupancy, $period) - self::paid($occupancy, $period);
    }
}
