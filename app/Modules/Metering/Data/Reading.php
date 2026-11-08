<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Enums\ReadingKind;
use DateTimeImmutable;

/**
 * Un relevé : un index (en millièmes) lu ou estimé sur un décompteur à une date.
 */
final readonly class Reading
{
    /**
     * @param EstimateOrigin|null $estimateOrigin pour un relevé estimé d'un mois clôturé : payeur et prix du mois
     */
    public function __construct(
        public ReadingKind $kind,
        public DateTimeImmutable $readOn,
        public int $index,
        public bool $isEstimated = false,
        public ?int $occupancyId = null,
        public ?EstimateOrigin $estimateOrigin = null,
    ) {}

    public function sortKey(): string
    {
        return $this->readOn->format('Y-m-d') . '#' . $this->kind->rank();
    }
}
