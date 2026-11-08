<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

final readonly class AllocationResult
{
    /**
     * @param array<string, AllocatedLine> $lines indexées par AllocationLine::$key, dans l'ordre d'entrée
     * @param string $unitPrice prix unitaire exact en FCFA par kWh ou m³ (fraction « a/b »)
     */
    public function __construct(
        public int $totalAmount,
        public int $totalConsumption,
        public string $unitPrice,
        public array $lines,
    ) {}

    public function amountFor(string $key): int
    {
        return $this->lines[$key]->amount;
    }

    public function sum(): int
    {
        return array_sum(array_map(static fn (AllocatedLine $l): int => $l->amount, $this->lines));
    }
}
