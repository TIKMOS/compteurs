<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

/**
 * Tranche : consommation brute entre deux relevés consécutifs d'un décompteur,
 * attribuée à une occupation ou, si null, au propriétaire / vacant (EF-11).
 */
final readonly class Segment
{
    public function __construct(
        public Reading $start,
        public Reading $end,
        public ?int $occupancyId,
    ) {}

    /** Consommation brute en millièmes ; négative seulement après un relevé estimé. */
    public function rawConsumption(): int
    {
        return $this->end->index - $this->start->index;
    }

    public function isEstimated(): bool
    {
        return $this->end->isEstimated;
    }

    public function isRegularization(): bool
    {
        return $this->start->isEstimated;
    }
}
