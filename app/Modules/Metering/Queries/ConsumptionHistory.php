<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\Tenant;
use App\Models\Unit;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\PriceSource;
use App\Shared\Enums\Utility;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historique des 12 derniers mois clôturés d'un logement ou d'un locataire (lot 2, tranche 4) :
 * par mois et par fluide, consommation facturée, montant, prix appliqué, estimé ou réel.
 * Lu uniquement dans les participations figées à la clôture (charges), jamais recalculé.
 */
final class ConsumptionHistory
{
    /**
     * Prix appliqué du mois en FCFA par kWh ou m³, figé sur la facture : « 136,385 » (2 décimales si prix fixé).
     */
    private static function price(Charge $charge): string
    {
        $bill = $charge->utilityBill;
        if ($bill->unit_price === null) {
            return '—';
        }

        $fixed = $bill->price_source === PriceSource::Fixed->value;

        return ResolvedPrice::format(Fraction::parse($bill->unit_price), $fixed ? 2 : null);
    }

    /**
     * Logement : toutes les participations du logement (locataires successifs et vacance).
     *
     * @return list<array<string, mixed>>
     */
    public function forUnit(Unit $unit, int $limit = 12): array
    {
        return $this->build(fn (Builder $q) => $q->where('unit_id', $unit->id), $limit);
    }

    /**
     * Locataire : ses participations, tous logements confondus.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(Tenant $tenant, int $limit = 12): array
    {
        return $this->build(
            fn (Builder $q) => $q->whereHas('occupancy', fn (Builder $o) => $o->where('tenant_id', $tenant->id)),
            $limit,
        );
    }

    /**
     * @param Closure(Builder<Charge>): mixed $scope
     *
     * @return list<array{period: BillingPeriod, total: int, utilities: array<string, array{consumption: int, amount: int, prices: list<string>, estimated: bool, regularized: bool}>}>
     */
    private function build(Closure $scope, int $limit): array
    {
        $periods = BillingPeriod::query()
            ->where('status', PeriodStatus::Closed)
            ->whereHas('bills.charges', $scope)
            ->orderByDesc('month')
            ->limit($limit)
            ->with('house')
            ->get();

        $charges = Charge::query()
            ->tap($scope)
            ->whereHas('utilityBill', fn (Builder $q) => $q->whereIn('billing_period_id', $periods->pluck('id')))
            ->with('utilityBill')
            ->get()
            ->groupBy(fn (Charge $c): int => $c->utilityBill->billing_period_id);

        $rows = [];
        foreach ($periods as $period) {
            $utilities = [];
            foreach (Utility::cases() as $utility) {
                $lines = $charges->get($period->id, collect())->filter(fn (Charge $c): bool => $c->utilityBill->utility === $utility);
                if ($lines->isEmpty()) {
                    continue;
                }

                $utilities[$utility->value] = [
                    'consumption' => $lines->sum(fn (Charge $c): int => Milli::fromDecimal($c->consumption)),
                    'amount' => (int) $lines->sum('amount'),
                    // Un seul prix en général ; plusieurs si le locataire a deux logements dans deux maisons.
                    'prices' => array_values($lines->map(fn (Charge $c): string => self::price($c))->unique()->all()),
                    'estimated' => $lines->contains(fn (Charge $c): bool => $c->is_estimated),
                    'regularized' => $lines->contains(fn (Charge $c): bool => $c->includes_regularization),
                ];
            }

            $rows[] = [
                'period' => $period,
                'total' => array_sum(array_column($utilities, 'amount')),
                'utilities' => $utilities,
            ];
        }

        return $rows;
    }
}
