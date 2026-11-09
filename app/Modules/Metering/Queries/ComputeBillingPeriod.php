<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\ConsumptionCredit;
use App\Models\Occupancy;
use App\Models\SubMeter;
use App\Models\Unit;
use App\Modules\Metering\Data\CreditLot;
use App\Modules\Metering\Data\EstimateOrigin;
use App\Modules\Metering\Data\OccupancySpan;
use App\Modules\Metering\Data\PeriodComputation;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Exceptions\IndexLowerThanPreviousException;
use App\Modules\Metering\Exceptions\MissingBillException;
use App\Modules\Metering\Exceptions\ZeroConsumptionException;
use App\Modules\Metering\Services\CreditSettlementCalculator;
use App\Modules\Metering\Services\SegmentBuilder;
use App\Modules\Metering\Services\UtilityAllocator;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;
use App\Shared\Support\ValueTooLargeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Calcule un mois à partir de la base, sans rien écrire (UC-13, CONCEPTION.md § 3.3) :
 * charge relevés, occupations, lots de crédit ouverts, facture et tarification du mois, puis
 * appelle le cœur de calcul pur (UtilityAllocator). Sert à l'écran de résultat en direct
 * (EF-30) et à la clôture (CloseBillingPeriod), qui fige ce même résultat.
 */
final class ComputeBillingPeriod
{
    public function __construct(
        private readonly UtilityAllocator $allocator,
        private readonly SegmentBuilder $segmentBuilder,
        private readonly CreditSettlementCalculator $settlementCalculator,
    ) {}

    /**
     * « d'électricité », « de novembre 2026 », « d'octobre 2026 ».
     */
    public static function of(string $word): string
    {
        return (preg_match('/^[aeiouéèêh]/iu', $word) === 1 ? 'd\'' : 'de ') . $word;
    }

    /**
     * Tranches d'un logement pour un fluide sur le mois (EF-11), tous décompteurs confondus :
     * sert à détailler les index sur la fiche d'un locataire (EF-36).
     *
     * @return list<\App\Modules\Metering\Data\Segment>
     */
    public function segmentsFor(Unit $unit, Utility $utility, BillingPeriod $period): array
    {
        $unit->loadMissing(['subMeters.readings', 'occupancies']);
        $segments = [];
        foreach ($this->metersOfMonth($unit, $utility, $period->month, $period->monthEnd()) as $meter) {
            array_push($segments, ...$this->segmentBuilder->build($this->readingsOfMonth($meter, $period), $this->spans($unit)));
        }

        usort($segments, fn ($a, $b): int => strcmp($a->end->sortKey(), $b->end->sortKey()));

        return $segments;
    }

    public function execute(BillingPeriod $period): PeriodComputation
    {
        $period->loadMissing(['house.mainMeters', 'house.pricings', 'bills']);
        $house = $period->house;
        $month = $period->month;
        $monthEnd = $period->monthEnd();
        $label = $period->label();

        $problems = [];

        $earlierDraft = BillingPeriod::query()
            ->where('house_id', $house->id)
            ->where('month', '<', $month->toDateString())
            ->where('status', PeriodStatus::Draft)
            ->orderBy('month')
            ->first();
        if ($earlierDraft !== null) {
            $problems[] = 'Clôturez d\'abord ' . $earlierDraft->label() . ' : les mois se clôturent dans l\'ordre.';
        }

        /** @var Collection<int, Unit> $units */
        $units = Unit::withTrashed()
            ->where('house_id', $house->id)
            ->with(['subMeters.readings', 'occupancies.tenant'])
            ->get();

        $occupantNames = [];
        foreach ($units as $unit) {
            foreach ($unit->occupancies as $occupancy) {
                $occupantNames[$occupancy->id] = $occupancy->tenant->name;
            }
        }

        $allocations = [];
        $pricings = [];
        $bills = [];

        foreach (Utility::cases() as $utility) {
            $pricing = $house->pricingFor($utility, $month);
            $pricings[$utility->value] = $pricing;
            $bill = $period->bill($utility)?->total_amount;
            $bills[$utility->value] = $bill;

            $inputs = [];
            $missing = false;
            foreach (Unit::sortNaturally(new \Illuminate\Database\Eloquent\Collection($units->all())) as $unit) {
                $subMeters = [];
                foreach ($this->metersOfMonth($unit, $utility, $month, $monthEnd) as $meter) {
                    $readings = $this->readingsOfMonth($meter, $period);
                    if ($meter->needsMonthEndReading($period) && ! $this->hasMonthEnd($readings)) {
                        $problems[] = "{$unit->label} ({$utility->label()}) : relevé de fin de mois manquant — saisissez-le ou estimez-le.";
                        $missing = true;
                    }
                    $subMeters[] = ['id' => $meter->id, 'readings' => $readings];
                }

                if ($subMeters === []) {
                    continue;
                }

                $inputs[] = [
                    'unitId' => $unit->id,
                    'unitLabel' => $unit->label,
                    'occupancies' => $this->spans($unit),
                    'subMeters' => $subMeters,
                    'creditLots' => $this->openLots($unit, $utility, $month),
                ];
            }

            // Aucun décompteur de ce fluide dans la maison : rien à répartir (H-53).
            if ($missing || $inputs === []) {
                continue;
            }

            try {
                $allocations[$utility->value] = $this->allocator->allocateWithPricing($pricing->rule(), $bill, $inputs);
            } catch (MissingBillException) {
                $problems[] = sprintf(
                    'Facture %s %s manquante%s',
                    self::of($utility->label()),
                    self::of($label),
                    $pricing->rule()->capToActual && $pricing->rule()->fixedUnitPrice !== null
                        ? ' : elle est obligatoire avec le plafond au prix réel.'
                        : '.',
                );
            } catch (ZeroConsumptionException) {
                $problems[] = sprintf(
                    'Aucune consommation %s relevée en %s (somme des décompteurs = 0 %s). La facture de %s FCFA ne peut pas être répartie : vérifiez les index saisis.',
                    self::of($utility->label()),
                    $label,
                    $utility->unit(),
                    Milli::format(((int) $bill) * 1000),
                );
            } catch (IndexLowerThanPreviousException|ValueTooLargeException $e) {
                $problems[] = Str::ucfirst($utility->label()) . ' : ' . $e->getMessage();
            }
        }

        return new PeriodComputation(
            $period,
            $allocations,
            $pricings,
            $bills,
            $problems,
            $this->settlements($allocations),
            $occupantNames,
        );
    }

    /**
     * Décompteurs du fluide en place pendant au moins une partie du mois.
     *
     * @return list<SubMeter>
     */
    private function metersOfMonth(Unit $unit, Utility $utility, CarbonImmutable $month, CarbonImmutable $monthEnd): array
    {
        return $unit->subMeters
            ->filter(fn (SubMeter $m): bool => $m->utility === $utility
                && $m->installed_on->lessThanOrEqualTo($monthEnd)
                && ($m->removed_on === null || $m->removed_on->greaterThanOrEqualTo($month)))
            ->sortBy(fn (SubMeter $m): string => $m->installed_on->toDateString())
            ->values()
            ->all();
    }

    /**
     * Point de départ (dernier relevé jusqu'au 1er du mois inclus : fin du mois précédent ou
     * pose) puis relevés du mois. Un départ estimé porte son origine (payeur et prix du mois
     * surestimé) pour créer le lot de crédit si l'index réel est plus bas (EF-24 b).
     *
     * @return list<Reading>
     */
    private function readingsOfMonth(SubMeter $meter, BillingPeriod $period): array
    {
        $startKey = (new Reading(ReadingKind::Initial, $period->month, 0))->sortKey();
        $chain = $meter->readingChain(fromLoaded: true);
        $start = $chain[0];
        $inMonth = [];

        foreach ($chain as $reading) {
            if (strcmp($reading->sortKey(), $startKey) <= 0) {
                $start = $reading;
            } elseif ($reading->readOn <= $period->monthEnd()) {
                $inMonth[] = $reading;
            }
        }

        if ($start->isEstimated && $start->kind === ReadingKind::MonthEnd) {
            $start = new Reading($start->kind, $start->readOn, $start->index, true, $start->occupancyId, $this->estimateOrigin($meter, $period));
        }

        return [$start, ...$inMonth];
    }

    /**
     * Payeur de la tranche estimée du mois précédent (rejouée avec les mêmes règles, EF-11) et
     * prix appliqué figé de ce mois (D-20). Le mois précédent est clôturé (clôture dans l'ordre).
     */
    private function estimateOrigin(SubMeter $meter, BillingPeriod $period): ?EstimateOrigin
    {
        $previous = BillingPeriod::query()
            ->where('house_id', $period->house_id)
            ->where('month', $period->month->subMonthNoOverflow()->toDateString())
            ->with('bills')
            ->first();

        $unitPrice = $previous?->bill($meter->utility)?->unit_price;
        if ($previous === null || $unitPrice === null) {
            return null;
        }

        $unit = $meter->unit()->withTrashed()->with('occupancies')->firstOrFail();
        $segments = $this->segmentBuilder->build($this->readingsOfMonth($meter, $previous), $this->spans($unit));
        $last = end($segments);

        return new EstimateOrigin(
            $last === false ? null : $last->occupancyId,
            Fraction::parse($unitPrice),
            $previous->month->format('Y-m'),
        );
    }

    /**
     * @param list<Reading> $readings
     */
    private function hasMonthEnd(array $readings): bool
    {
        foreach (array_slice($readings, 1) as $reading) {
            if ($reading->kind === ReadingKind::MonthEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<OccupancySpan>
     */
    private function spans(Unit $unit): array
    {
        return $unit->occupancies
            ->map(fn (Occupancy $o): OccupancySpan => new OccupancySpan($o->id, $o->started_on, $o->ended_on))
            ->values()
            ->all();
    }

    /**
     * Lots de crédit ouverts (reste > 0) créés dans des mois clôturés antérieurs, par payeur,
     * du plus ancien au plus récent (D-14).
     *
     * @return array<string, list<CreditLot>>
     */
    private function openLots(Unit $unit, Utility $utility, CarbonImmutable $month): array
    {
        $lots = ConsumptionCredit::query()
            ->where('unit_id', $unit->id)
            ->where('utility', $utility)
            ->whereHas('createdInPeriod', fn ($q) => $q->where('status', PeriodStatus::Closed)->where('month', '<', $month->toDateString()))
            ->with(['movements', 'originPeriod'])
            ->orderBy('id')
            ->get();

        $byPayer = [];
        foreach ($lots as $lot) {
            $remaining = $lot->remaining();
            if ($remaining > 0) {
                $byPayer[CreditLot::payerKey($lot->occupancy_id)][] = new CreditLot(
                    $lot->occupancy_id,
                    $remaining,
                    Fraction::parse($lot->unit_price),
                    $lot->originPeriod->month->format('Y-m'),
                    $lot->id,
                );
            }
        }

        return $byPayer;
    }

    /**
     * Soldes de crédit des locataires sortants (EF-24 f) : valeur électricité + eau arrondie
     * une seule fois (D-15), déduite de leur fiche du mois.
     *
     * @param array<string, \App\Modules\Metering\Data\UtilityAllocation> $allocations
     *
     * @return array<int, \App\Modules\Metering\Data\CreditSettlement>
     */
    private function settlements(array $allocations): array
    {
        $values = [];
        $totals = [];

        foreach ($allocations as $allocation) {
            foreach ($allocation->credits as $credit) {
                foreach ($credit->settlements as $occupancyId => $settled) {
                    $values[$occupancyId][] = $settled->value;
                }
            }
            foreach ($allocation->payers as $payer) {
                if ($payer->occupancyId !== null) {
                    $totals[$payer->occupancyId] = ($totals[$payer->occupancyId] ?? 0) + $allocation->result->amountFor($payer->key);
                }
            }
        }

        $settlements = [];
        foreach ($values as $occupancyId => $creditValues) {
            $settlements[$occupancyId] = $this->settlementCalculator->settle($totals[$occupancyId] ?? 0, $creditValues);
        }

        return $settlements;
    }
}
