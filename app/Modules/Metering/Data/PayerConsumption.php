<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

/**
 * Consommation d'un payeur pour un logement et un fluide sur la période :
 * c'est le contenu d'une future ligne `charges`, avant le montant.
 */
final readonly class PayerConsumption
{
    public function __construct(
        public string $key,
        public int $unitId,
        public string $unitLabel,
        public ?int $occupancyId,
        public ?string $startedOn,
        public int $raw,
        public int $creditApplied,
        public int $billed,
        public bool $isEstimated,
        public bool $includesRegularization,
    ) {}

    public static function keyFor(int $unitId, ?int $occupancyId): string
    {
        return "unit:{$unitId}|" . ($occupancyId === null ? 'vacant' : "occ:{$occupancyId}");
    }

    public function isVacant(): bool
    {
        return $this->occupancyId === null;
    }

    public function toAllocationLine(): AllocationLine
    {
        return new AllocationLine($this->key, $this->unitLabel, $this->billed, $this->startedOn);
    }
}
