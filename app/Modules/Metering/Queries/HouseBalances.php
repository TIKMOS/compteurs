<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\CreditSettlement;
use App\Models\House;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Shared\Enums\PaymentStatus;
use App\Shared\Enums\PeriodStatus;
use LogicException;

/**
 * Suivi des paiements d'une maison (UC-18) : pour chaque locataire et chaque mois clôturé, dû,
 * payé, reste ; et les remboursements de crédit encore dus aux locataires sortis.
 */
final class HouseBalances
{
    /**
     * @return array{
     *     tenants: list<array{occupancy: Occupancy, months: list<array{period: BillingPeriod, due: int, paid: int, remaining: int, status: PaymentStatus, payments: list<Payment>}>, remaining: int}>,
     *     unpaid: list<array{occupancy: Occupancy, period: BillingPeriod, due: int, paid: int, remaining: int, status: PaymentStatus, payments: list<Payment>}>,
     *     totalRemaining: int,
     *     refunds: list<CreditSettlement>,
     *     totalRefunds: int
     * }
     */
    public function execute(House $house): array
    {
        $periods = $house->billingPeriods()->where('status', PeriodStatus::Closed)->orderByDesc('month')->get()->keyBy('id');

        $charges = Charge::query()
            ->whereNotNull('occupancy_id')
            ->whereHas('utilityBill', fn ($q) => $q->whereIn('billing_period_id', $periods->keys()))
            ->with('utilityBill:id,billing_period_id')
            ->get();

        $deducted = CreditSettlement::query()->whereIn('billing_period_id', $periods->keys())->get();
        $payments = Payment::query()->whereIn('billing_period_id', $periods->keys())->orderBy('paid_on')->get();

        $due = [];
        foreach ($charges as $charge) {
            $key = $charge->occupancy_id . '|' . $charge->utilityBill->billing_period_id;
            $due[$key] = ($due[$key] ?? 0) + $charge->amount;
        }
        foreach ($deducted as $settlement) {
            $key = $settlement->occupancy_id . '|' . $settlement->billing_period_id;
            $due[$key] = ($due[$key] ?? 0) - $settlement->deducted_amount;
        }

        $occupancies = Occupancy::query()
            ->whereIn('id', array_unique(array_map(fn (string $k): int => (int) explode('|', $k)[0], array_keys($due))))
            ->with(['tenant', 'unit'])
            ->get()
            ->keyBy('id');

        $tenants = [];
        foreach ($due as $key => $amount) {
            [$occupancyId, $periodId] = array_map('intval', explode('|', $key));
            $monthPayments = array_values($payments->where('occupancy_id', $occupancyId)->where('billing_period_id', $periodId)->all());
            $paid = array_sum(array_map(fn (Payment $p): int => $p->amount, $monthPayments));

            // Participations et périodes viennent des mêmes requêtes : les deux existent forcément.
            $tenants[$occupancyId] ??= ['occupancy' => $occupancies->get($occupancyId) ?? throw new LogicException('Occupation introuvable.'), 'months' => [], 'remaining' => 0];
            $tenants[$occupancyId]['months'][] = [
                'period' => $periods->get($periodId) ?? throw new LogicException('Période introuvable.'),
                'due' => $amount,
                'paid' => $paid,
                'remaining' => $amount - $paid,
                'status' => PaymentStatus::for($amount, $paid),
                'payments' => $monthPayments,
            ];
            $tenants[$occupancyId]['remaining'] += $amount - $paid;
        }

        foreach ($tenants as &$tenant) {
            usort($tenant['months'], fn (array $a, array $b): int => $b['period']->month <=> $a['period']->month);
        }
        unset($tenant);

        // Impayés d'abord, puis par logement (ordre naturel) et par locataire.
        uasort($tenants, fn (array $a, array $b): int => [$b['remaining'] > 0, 0] <=> [$a['remaining'] > 0, 0]
            ?: strnatcasecmp($a['occupancy']->unit->label, $b['occupancy']->unit->label)
            ?: strcmp($a['occupancy']->tenant->name, $b['occupancy']->tenant->name));

        $refunds = array_values($deducted
            ->filter(fn (CreditSettlement $s): bool => $s->refund_due > 0 && $s->refunded_at === null)
            ->each(fn (CreditSettlement $s) => $s->setRelation('billingPeriod', $periods[$s->billing_period_id])->load('occupancy.tenant', 'occupancy.unit'))
            ->all());

        // Liste des impayés de la maison : répartitions partielles ou impayées, plus récentes d'abord.
        $unpaid = [];
        foreach ($tenants as $tenant) {
            foreach ($tenant['months'] as $month) {
                if ($month['status'] !== PaymentStatus::Paid) {
                    $unpaid[] = ['occupancy' => $tenant['occupancy'], ...$month];
                }
            }
        }
        usort($unpaid, fn (array $a, array $b): int => $b['period']->month <=> $a['period']->month
            ?: strnatcasecmp($a['occupancy']->unit->label, $b['occupancy']->unit->label));

        return [
            'tenants' => array_values($tenants),
            'unpaid' => $unpaid,
            'totalRemaining' => array_sum(array_map(fn (array $t): int => max(0, $t['remaining']), $tenants)),
            'refunds' => $refunds,
            'totalRefunds' => array_sum(array_map(fn (CreditSettlement $s): int => $s->refund_due, $refunds)),
        ];
    }
}
