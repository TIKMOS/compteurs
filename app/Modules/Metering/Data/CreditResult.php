<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Enums\CreditMovementKind;

final readonly class CreditResult
{
    /**
     * @param list<BilledSegment> $segments dans l'ordre chronologique
     * @param array<string, list<CreditLot>> $closingLots lots restants par payeur (CreditLot::payerKey)
     * @param list<CreditLot> $createdLots lots créés ce mois (quantité initiale)
     * @param list<CreditMovement> $movements
     * @param array<int, SettledCredit> $settlements par occupation sortante
     */
    public function __construct(
        public array $segments,
        public array $closingLots,
        public array $createdLots,
        public array $movements,
        public array $settlements,
    ) {}

    /** Crédit restant (millièmes) d'un payeur après le mois. */
    public function balanceFor(?int $occupancyId): int
    {
        return array_sum(array_map(
            static fn (CreditLot $lot): int => $lot->quantity,
            $this->closingLots[CreditLot::payerKey($occupancyId)] ?? [],
        ));
    }

    public function created(): int
    {
        return array_sum(array_map(static fn (CreditLot $lot): int => $lot->quantity, $this->createdLots));
    }

    public function applied(): int
    {
        return array_sum(array_map(
            static fn (CreditMovement $m): int => $m->kind === CreditMovementKind::Applied ? $m->quantity : 0,
            $this->movements,
        ));
    }
}
