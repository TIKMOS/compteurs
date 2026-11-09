<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\ConsumptionCredit;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Models\Tenant;
use App\Models\Unit;
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
        return $this->executeMany($period, [$occupancy])[$occupancy->id];
    }

    /**
     * Fiches de plusieurs locataires d'un même mois, en chargeant une seule fois ce qui est commun
     * (revue M-6 : le résultat du mois faisait ~22 requêtes par locataire).
     *
     * @param iterable<Occupancy> $occupancies
     *
     * @return array<int, TenantStatement> par identifiant d'occupation
     */
    public function executeMany(BillingPeriod $period, iterable $occupancies): array
    {
        if (! $period->isClosed()) {
            throw new LogicException('La fiche n\'existe que pour un mois clôturé.');
        }

        // load() et non loadMissing() : des factures chargées avant la clôture n'ont pas les prix figés.
        $period->load(['house', 'bills', 'creditSettlements']);
        $occupancies = collect($occupancies);
        if ($occupancies->isEmpty()) {
            return [];
        }
        $occupancyIds = $occupancies->pluck('id')->all();

        // Un seul objet Unit par logement : ses relevés et occupations ne sont chargés qu'une fois.
        $units = Unit::withTrashed()->whereIn('id', $occupancies->pluck('unit_id')->unique()->all())->get()->keyBy('id');
        $tenants = Tenant::withTrashed()->whereIn('id', $occupancies->pluck('tenant_id')->unique()->all())->get()->keyBy('id');

        $charges = Charge::query()
            ->whereIn('utility_bill_id', $period->bills->pluck('id')->all())
            ->whereIn('occupancy_id', $occupancyIds)
            ->get()
            ->keyBy(fn (Charge $c): string => $c->utility_bill_id . '|' . $c->occupancy_id);

        $credits = ConsumptionCredit::query()
            ->whereIn('occupancy_id', $occupancyIds)
            ->with('movements')
            ->get()
            ->groupBy(fn (ConsumptionCredit $c): string => $c->occupancy_id . '|' . $c->unit_id . '|' . $c->utility->value);

        // Relevés de fin de mois du mois (photos, base d'estimation), par logement et par fluide.
        $monthEnd = $period->readings()
            ->where('kind', ReadingKind::MonthEnd)
            ->with('subMeter')
            ->get()
            ->groupBy(fn (MeterReading $r): string => $r->subMeter->unit_id . '|' . $r->subMeter->utility->value);

        $paid = $period->payments()
            ->whereIn('occupancy_id', $occupancyIds)
            ->get(['occupancy_id', 'amount'])
            ->groupBy('occupancy_id')
            ->map(fn ($payments): int => (int) $payments->sum('amount'));

        $segmentsCache = [];
        $statements = [];
        foreach ($occupancies as $occupancy) {
            $unit = $units->get($occupancy->unit_id);
            $occupancy->setRelation('unit', $unit);
            $occupancy->setRelation('tenant', $tenants->get($occupancy->tenant_id));

            $utilities = [];
            foreach (Utility::cases() as $utility) {
                $bill = $period->bill($utility);
                $charge = $bill === null ? null : $charges->get($bill->id . '|' . $occupancy->id);
                if ($charge === null) {
                    continue;
                }

                $key = $unit->id . '|' . $utility->value;
                $segmentsCache[$key] ??= $this->compute->segmentsFor($unit, $utility, $period);
                $segments = array_values(array_filter(
                    $segmentsCache[$key],
                    fn ($segment): bool => $segment->occupancyId === $occupancy->id,
                ));

                $estimatedEnd = collect($segments)->first(fn ($s): bool => $s->end->isEstimated);
                $readings = $monthEnd->get($key, collect());

                $utilities[] = [
                    'utility' => $utility,
                    'priceLabel' => ResolvedPrice::fromBill($bill)?->label($utility->unit(), forTenant: true) ?? '',
                    'segments' => array_map(fn ($s): array => [
                        'from' => self::describe($s->start),
                        'to' => self::describe($s->end),
                        'consumption' => $s->rawConsumption(),
                    ], $segments),
                    'raw' => Milli::fromDecimal($charge->raw_consumption),
                    'creditApplied' => Milli::fromDecimal($charge->credit_applied),
                    'consumption' => Milli::fromDecimal($charge->consumption),
                    'creditBalance' => (int) $credits->get($occupancy->id . '|' . $key, collect())
                        ->sum(fn (ConsumptionCredit $lot): int => $lot->remaining()),
                    'amount' => $charge->amount,
                    'estimated' => $charge->is_estimated,
                    'regularization' => $charge->includes_regularization,
                    'estimationBasis' => $estimatedEnd === null ? null : $readings->firstWhere('is_estimated', true)?->estimation_basis,
                    // Photos des relevés de fin de mois du logement (preuve, lot 3).
                    'photoReadingIds' => $readings->whereNotNull('photo_path')->pluck('id')->sort()->values()->all(),
                ];
            }

            $settlement = $period->creditSettlements->firstWhere('occupancy_id', $occupancy->id);
            $from = $occupancy->started_on->greaterThan($period->month) ? $occupancy->started_on : $period->month;
            $to = $occupancy->ended_on !== null && $occupancy->ended_on->lessThan($period->monthEnd()) ? $occupancy->ended_on : $period->monthEnd();

            $statements[$occupancy->id] = new TenantStatement(
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
                (int) ($paid[$occupancy->id] ?? 0),
                $period->house->paymentDueDate($period),
            );
        }

        return $statements;
    }
}
