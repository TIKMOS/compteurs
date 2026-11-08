<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\CreditSettlement;
use App\Modules\Metering\Data\TenantStatement;
use App\Modules\Metering\Exceptions\PaymentRejectedException;
use App\Modules\Metering\Support\Journal;
use Carbon\CarbonImmutable;

/**
 * Le propriétaire a remboursé au locataire sorti son crédit restant (EF-24 f, UC-18). Une fois
 * marqué remboursé, le mois de sortie ne peut plus être rouvert (H-29).
 */
final class MarkCreditRefunded
{
    /**
     * @throws PaymentRejectedException
     */
    public function execute(CreditSettlement $settlement, CarbonImmutable $refundedOn): CreditSettlement
    {
        if ($settlement->refund_due <= 0) {
            throw new PaymentRejectedException('Rien à rembourser à ce locataire.');
        }

        if ($settlement->refunded_at !== null) {
            throw new PaymentRejectedException('Ce remboursement est déjà marqué comme effectué.');
        }

        if ($refundedOn->startOfDay()->isAfter(CarbonImmutable::today())) {
            throw new PaymentRejectedException('La date du remboursement ne peut pas être dans le futur.');
        }

        $settlement->forceFill(['refunded_at' => $refundedOn->startOfDay()])->save();

        $settlement->loadMissing('occupancy.tenant');
        Journal::record('credit_refunded', sprintf(
            'Crédit remboursé à %s : %s le %s',
            $settlement->occupancy->tenant->name,
            TenantStatement::fcfa($settlement->refund_due),
            $refundedOn->format('d/m/Y'),
        ), $settlement, ['refund_due' => $settlement->refund_due]);

        return $settlement;
    }
}
