<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

/**
 * Tranche après application du crédit de consommation (EF-24).
 * billed = max(0, raw) − creditApplied, toujours ≥ 0.
 */
final readonly class BilledSegment
{
    public function __construct(
        public Segment $segment,
        public int $creditApplied,
        public int $creditCreated,
        public int $billed,
    ) {}

    public function raw(): int
    {
        return $this->segment->rawConsumption();
    }
}
