<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Payment;
use App\Modules\Metering\Data\TenantStatement;
use App\Modules\Metering\Support\Journal;

/**
 * Annule un paiement saisi par erreur (UC-18) ; l'annulation reste tracée au journal.
 */
final class CancelPayment
{
    public function execute(Payment $payment): void
    {
        $payment->loadMissing(['occupancy.tenant', 'billingPeriod']);

        Journal::record('payment_cancelled', sprintf(
            'Paiement annulé : %s, %s du %s (%s)',
            $payment->occupancy->tenant->name,
            TenantStatement::fcfa($payment->amount),
            $payment->paid_on->format('d/m/Y'),
            $payment->billingPeriod->label(),
        ), $payment, ['amount' => $payment->amount, 'method' => $payment->method->value]);

        $payment->delete();
    }
}
