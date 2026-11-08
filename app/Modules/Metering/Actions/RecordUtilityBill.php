<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\UtilityBill;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Shared\Enums\Utility;
use Illuminate\Support\Facades\DB;

/**
 * Saisie de la facture d'un fluide pour un mois (UC-12, EF-19, EF-20) : montant TTC en FCFA
 * et, pour information, consommation du compteur principal. Les deux champs vides effacent
 * la saisie. L'obligation de la facture selon le mode se vérifie à la clôture (EF-31).
 */
final class RecordUtilityBill
{
    /**
     * @param string|null $mainConsumption décimal « 490.000 »
     *
     * @throws PeriodClosedException
     */
    public function execute(BillingPeriod $period, Utility $utility, ?int $totalAmount, ?string $mainConsumption): ?UtilityBill
    {
        return DB::transaction(function () use ($period, $utility, $totalAmount, $mainConsumption): ?UtilityBill {
            $period = BillingPeriod::query()->whereKey($period->id)->lockForUpdate()->with('house.mainMeters')->firstOrFail();
            if ($period->isClosed()) {
                throw new PeriodClosedException($period);
            }

            $bill = $period->bills()->where('utility', $utility)->first();

            if ($totalAmount === null && $mainConsumption === null) {
                $bill?->delete();

                return null;
            }

            $attributes = [
                'main_meter_id' => $period->house->mainMeter($utility)->id,
                'total_amount' => $totalAmount,
                'main_consumption' => $mainConsumption,
            ];

            if ($bill !== null) {
                $bill->update($attributes);

                return $bill;
            }

            return $period->bills()->create([...$attributes, 'utility' => $utility]);
        });
    }
}
