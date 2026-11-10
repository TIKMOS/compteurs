<?php

declare(strict_types=1);

namespace App\Modules\Metering\Livewire;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\ConsumptionCredit;
use App\Modules\Metering\Data\PeriodComputation;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use LogicException;

/**
 * Récapitulatif d'un mois pour l'écran de résultat (UC-13, EF-36, EF-42, EF-43) : calcul en
 * direct pour un brouillon, valeurs figées lues en base pour un mois clôturé (ENF-08). Même
 * forme dans les deux cas pour un seul gabarit d'affichage.
 */
final readonly class PeriodSummary
{
    /**
     * @param list<array<string, mixed>> $utilities un bloc par fluide calculé
     * @param list<array<string, mixed>> $tenants fiche par locataire du mois
     * @param list<string> $problems
     */
    private function __construct(
        public BillingPeriod $period,
        public array $utilities,
        public array $tenants,
        public array $problems,
        public bool $canClose,
        public int $creditRefunds,
    ) {}

    public static function fromComputation(PeriodComputation $computation): self
    {
        $utilities = [];
        $tenants = [];
        $period = $computation->period;

        foreach ($computation->allocations as $utilityValue => $allocation) {
            $utility = Utility::from($utilityValue);
            $lines = [];
            foreach ($allocation->payers as $payer) {
                $lines[] = [
                    'unitLabel' => $payer->unitLabel,
                    'occupancyId' => $payer->occupancyId,
                    'payer' => $payer->occupancyId === null ? 'Propriétaire / vacant' : $computation->occupantNames[$payer->occupancyId],
                    'consumption' => $payer->billed,
                    'creditApplied' => $payer->creditApplied,
                    'creditBalance' => $allocation->credits[$payer->unitId]->balanceFor($payer->occupancyId),
                    'amount' => $allocation->result->amountFor($payer->key),
                    'estimated' => $payer->isEstimated,
                    'regularization' => $payer->includesRegularization,
                ];
            }

            $utilities[] = self::utilityBlock(
                $utility,
                $allocation->price ?? throw new LogicException('Prix non résolu.'),
                $computation->bills[$utilityValue],
                $period->bill($utility)?->main_consumption,
                $allocation->result->totalConsumption,
                $lines,
            );
        }

        $settlements = [];
        foreach ($computation->settlements as $occupancyId => $s) {
            $settlements[$occupancyId] = ['creditValue' => $s->creditValue, 'deducted' => $s->deductedAmount, 'refundDue' => $s->refundDue];
        }

        return new self(
            $period,
            $utilities,
            self::tenants($utilities, $settlements),
            $computation->problems,
            $computation->canClose(),
            array_sum(array_column($settlements, 'creditValue')),
        );
    }

    public static function fromClosed(BillingPeriod $period): self
    {
        $period->loadMissing(['bills.charges.unit', 'bills.charges.occupancy.tenant', 'creditSettlements']);
        $utilities = [];

        // Crédits restants de tous les logements du mois, en une requête (revue M-6).
        $credits = ConsumptionCredit::query()
            ->whereIn('unit_id', $period->bills->flatMap->charges->pluck('unit_id')->unique()->all())
            ->with('movements')
            ->get()
            ->groupBy(fn (ConsumptionCredit $c): string => $c->unit_id . '|' . $c->utility->value . '|' . ($c->occupancy_id ?? ''));

        foreach (Utility::cases() as $utility) {
            $bill = $period->bill($utility);
            if ($bill === null || $bill->unit_price === null) {
                continue;
            }

            $price = ResolvedPrice::fromBill($bill);
            if ($price === null) {
                continue;
            }

            $charges = $bill->charges->sortBy(fn (Charge $c): string => mb_strtolower($c->unit->label) . '|' . ($c->occupancy?->started_on->toDateString() ?? '9'), SORT_NATURAL);
            $lines = [];
            foreach ($charges as $charge) {
                $lines[] = [
                    'unitLabel' => $charge->unit->label,
                    'occupancyId' => $charge->occupancy_id,
                    'payer' => $charge->occupancy?->tenant->name ?? 'Propriétaire / vacant',
                    'consumption' => Milli::fromDecimal($charge->consumption),
                    'creditApplied' => Milli::fromDecimal($charge->credit_applied),
                    'creditBalance' => (int) $credits->get($charge->unit_id . '|' . $utility->value . '|' . ($charge->occupancy_id ?? ''), collect())
                        ->sum(fn (ConsumptionCredit $lot): int => $lot->remaining()),
                    'amount' => $charge->amount,
                    'estimated' => $charge->is_estimated,
                    'regularization' => $charge->includes_regularization,
                ];
            }

            $utilities[] = self::utilityBlock(
                $utility,
                $price,
                $bill->total_amount,
                $bill->main_consumption,
                Milli::fromDecimal($bill->total_consumption ?? '0'),
                $lines,
            );
        }

        $settlements = [];
        foreach ($period->creditSettlements as $s) {
            $settlements[$s->occupancy_id] = ['creditValue' => $s->credit_value, 'deducted' => $s->deducted_amount, 'refundDue' => $s->refund_due];
        }

        return new self($period, $utilities, self::tenants($utilities, $settlements), [], false, array_sum(array_column($settlements, 'creditValue')));
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private static function utilityBlock(Utility $utility, ResolvedPrice $price, ?int $bill, ?string $mainConsumption, int $totalConsumption, array $lines): array
    {
        $tenantsTotal = array_sum(array_map(fn (array $l): int => $l['occupancyId'] === null ? 0 : $l['amount'], $lines));
        $vacantTotal = array_sum(array_map(fn (array $l): int => $l['occupancyId'] === null ? $l['amount'] : 0, $lines));
        $fixedMode = $price->rule->mode === PricingMode::FixedPrice;
        $main = $mainConsumption === null ? null : Milli::fromDecimal($mainConsumption);

        return [
            'utility' => $utility,
            'priceLabel' => $price->label($utility->unit()),
            'bill' => $bill,
            'totalConsumption' => $totalConsumption,
            // EF-20 : écart de comptage, information seulement.
            'countingGap' => $main === null ? null : $main - $totalConsumption,
            'countingGapPct' => $main === null || $main === 0 ? null : number_format(($main - $totalConsumption) * 100 / $main, 2, ',', ''),
            'lines' => $lines,
            'tenantsTotal' => $tenantsTotal,
            'vacantTotal' => $vacantTotal,
            'fixedMode' => $fixedMode,
            // Mode 2 : reste à la charge du propriétaire (plafonné), écart d'encaissement (EF-43, H-38).
            'ownerRemainder' => $fixedMode && $bill !== null && $price->rule->capToActual ? $bill - $tenantsTotal : null,
            'collectionGap' => $fixedMode && $bill !== null && ! $price->rule->capToActual ? $tenantsTotal - $bill : null,
        ];
    }

    /**
     * Fiche par locataire : participation par fluide, total, solde de crédit à la sortie.
     *
     * @param list<array<string, mixed>> $utilities
     * @param array<int, array{creditValue: int, deducted: int, refundDue: int}> $settlements
     *
     * @return list<array<string, mixed>>
     */
    private static function tenants(array $utilities, array $settlements): array
    {
        $tenants = [];
        foreach ($utilities as $block) {
            foreach ($block['lines'] as $line) {
                if ($line['occupancyId'] === null) {
                    continue;
                }
                $id = $line['occupancyId'];
                $tenants[$id] ??= ['occupancyId' => $id, 'name' => $line['payer'], 'unitLabel' => $line['unitLabel'], 'amounts' => [], 'estimated' => false, 'total' => 0];
                $tenants[$id]['amounts'][$block['utility']->value] = $line['amount'];
                $tenants[$id]['estimated'] = $tenants[$id]['estimated'] || $line['estimated'];
                $tenants[$id]['total'] += $line['amount'];
            }
        }

        foreach ($tenants as $id => &$tenant) {
            $tenant['settlement'] = $settlements[$id] ?? null;
            $tenant['net'] = $tenant['total'] - ($settlements[$id]['deducted'] ?? 0);
        }
        unset($tenant);

        uasort($tenants, fn (array $a, array $b): int => strnatcasecmp($a['unitLabel'], $b['unitLabel']) ?: strcmp($a['name'], $b['name']));

        return array_values($tenants);
    }

    /**
     * Crédit restant aujourd'hui d'un payeur, pour un logement et un fluide.
     */
}
