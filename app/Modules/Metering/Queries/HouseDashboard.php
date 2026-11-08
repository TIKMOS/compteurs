<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\House;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;

/**
 * Tableau de bord d'une maison (lot 2) : par mois clôturé et par fluide, facture, participations
 * des locataires, part du propriétaire (vacance et reste en mode 2) et écart de comptage
 * (EF-20, information : fuite ou branchement illicite possible).
 */
final class HouseDashboard
{
    /**
     * @return list<array{period: BillingPeriod, utilities: array<string, array{bill: int|null, tenants: int, owner: int|null, total: int, mainConsumption: int|null, meters: int, gap: int|null, gapPct: string|null}>, estimated: int}>
     */
    public function months(House $house, int $limit = 12): array
    {
        $periods = $house->billingPeriods()
            ->where('status', PeriodStatus::Closed)
            ->orderByDesc('month')
            ->limit($limit)
            ->with(['bills.charges'])
            ->get();

        $rows = [];
        foreach ($periods as $period) {
            $utilities = [];
            $estimated = 0;

            foreach (Utility::cases() as $utility) {
                $bill = $period->bill($utility);
                if ($bill === null || $bill->unit_price === null) {
                    continue;
                }

                $charges = $bill->charges;
                $tenants = (int) $charges->whereNotNull('occupancy_id')->sum('amount');
                $vacant = (int) $charges->whereNull('occupancy_id')->sum('amount');
                $estimated += $charges->filter(fn (Charge $c): bool => $c->is_estimated)->count();

                $meters = Milli::fromDecimal($bill->total_consumption ?? '0');
                $main = $bill->main_consumption === null ? null : Milli::fromDecimal($bill->main_consumption);

                $utilities[$utility->value] = [
                    'bill' => $bill->total_amount,
                    'tenants' => $tenants,
                    // Part du propriétaire : facture − participations des locataires (vacance comprise).
                    'owner' => $bill->total_amount === null ? null : $bill->total_amount - $tenants,
                    'total' => $tenants + $vacant,
                    'mainConsumption' => $main,
                    'meters' => $meters,
                    'gap' => $main === null ? null : $main - $meters,
                    'gapPct' => $main === null || $main === 0 ? null : number_format(($main - $meters) * 100 / $main, 1, ',', ''),
                ];
            }

            $rows[] = ['period' => $period, 'utilities' => $utilities, 'estimated' => $estimated];
        }

        return $rows;
    }
}
