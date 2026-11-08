<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

/**
 * Solde du crédit d'un locataire sortant sur sa dernière fiche (EF-24 f) : ligne `credit_settlements`.
 */
final readonly class CreditSettlement
{
    public function __construct(
        public int $statementTotal,
        public int $creditValue,
        public int $deductedAmount,
        public int $refundDue,
    ) {}

    /** Net à payer sur la dernière fiche après déduction. */
    public function netDue(): int
    {
        return $this->statementTotal - $this->deductedAmount;
    }
}
