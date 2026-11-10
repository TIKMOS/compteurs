<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\UtilityBill;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Modules\Metering\Support\Journal;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
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

            $what = sprintf('Facture %s %s', ComputeBillingPeriod::of($utility->label()), ComputeBillingPeriod::of($period->label()));

            if ($totalAmount === null && $mainConsumption === null) {
                if ($bill !== null) {
                    Journal::record('bill_cleared', "{$what} effacée", $bill, ['total_amount' => $bill->total_amount]);
                    $bill->delete();
                }

                return null;
            }

            $attributes = [
                'main_meter_id' => $period->house->requireMainMeter($utility)->id,
                'total_amount' => $totalAmount,
                'main_consumption' => $mainConsumption,
            ];

            $previous = $bill?->total_amount;
            if ($bill !== null) {
                $bill->update($attributes);
            } else {
                $bill = $period->bills()->create([...$attributes, 'utility' => $utility]);
            }

            Journal::record('bill_recorded', $what . ' : ' . ($totalAmount === null ? 'montant non renseigné' : Milli::format($totalAmount * 1000) . ' FCFA'), $bill, [
                'total_amount' => $totalAmount,
                'previous_total_amount' => $previous,
                'main_consumption' => $mainConsumption,
            ]);

            return $bill;
        });
    }
}
