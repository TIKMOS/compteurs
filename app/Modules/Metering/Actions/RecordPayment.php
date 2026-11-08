<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Metering\Data\TenantStatement;
use App\Modules\Metering\Exceptions\PaymentRejectedException;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Modules\Metering\Queries\TenantBalance;
use App\Modules\Metering\Support\Journal;
use App\Shared\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre un paiement reçu d'un locataire pour un mois clôturé (UC-18). Les paiements
 * partiels sont permis ; un montant supérieur au reste dû est refusé (H-65).
 */
final class RecordPayment
{
    /**
     * @throws PaymentRejectedException
     */
    public function execute(
        Occupancy $occupancy,
        BillingPeriod $period,
        int $amount,
        CarbonImmutable $paidOn,
        PaymentMethod $method,
        User $by,
        ?string $note = null,
    ): Payment {
        return DB::transaction(function () use ($occupancy, $period, $amount, $paidOn, $method, $by, $note): Payment {
            $period = BillingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $occupancy->loadMissing(['tenant', 'unit']);

            if (! $period->isClosed()) {
                throw new PaymentRejectedException('Les paiements s\'enregistrent sur un mois clôturé : clôturez d\'abord ' . $period->label() . '.');
            }

            if ($occupancy->unit->house_id !== $period->house_id) {
                throw new PaymentRejectedException('Ce locataire n\'occupe pas un logement de cette maison.');
            }

            if ($amount <= 0) {
                throw new PaymentRejectedException('Le montant doit être positif.');
            }

            if ($paidOn->startOfDay()->isAfter(CarbonImmutable::today())) {
                throw new PaymentRejectedException('La date du paiement ne peut pas être dans le futur.');
            }

            $remaining = TenantBalance::remaining($occupancy, $period);
            if ($amount > $remaining) {
                throw new PaymentRejectedException($remaining <= 0
                    ? 'Ce mois est déjà entièrement payé.'
                    : 'Le montant dépasse le reste dû pour ' . $period->label() . ' : ' . TenantStatement::fcfa($remaining) . '.');
            }

            $payment = new Payment([
                'occupancy_id' => $occupancy->id,
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => $method,
                'note' => filled($note) ? trim($note) : null,
                'recorded_by' => $by->id,
            ]);
            $payment->billingPeriod()->associate($period);
            $payment->save();

            Journal::record('payment_recorded', sprintf(
                'Paiement de %s (%s) : %s le %s, %s, pour %s',
                $occupancy->tenant->name,
                $occupancy->unit->label,
                TenantStatement::fcfa($amount),
                $paidOn->format('d/m/Y'),
                $method->label(),
                ComputeBillingPeriod::of($period->label()),
            ), $payment, ['amount' => $amount, 'remaining_after' => $remaining - $amount]);

            return $payment;
        });
    }
}
