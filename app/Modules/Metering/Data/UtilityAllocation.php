<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use OutOfBoundsException;

/**
 * Résultat complet de la répartition d'un fluide sur une période : ce que la clôture fige.
 */
final readonly class UtilityAllocation
{
    /**
     * @param list<PayerConsumption> $payers
     * @param array<int, CreditResult> $credits par identifiant de logement
     */
    public function __construct(
        public array $payers,
        public AllocationResult $result,
        public array $credits,
    ) {}

    public function amountFor(int $unitId, ?int $occupancyId): int
    {
        return $this->result->amountFor(PayerConsumption::keyFor($unitId, $occupancyId));
    }

    public function payer(int $unitId, ?int $occupancyId): PayerConsumption
    {
        $key = PayerConsumption::keyFor($unitId, $occupancyId);
        foreach ($this->payers as $payer) {
            if ($payer->key === $key) {
                return $payer;
            }
        }

        throw new OutOfBoundsException("Aucun payeur {$key}.");
    }
}
