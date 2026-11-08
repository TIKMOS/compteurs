<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

final readonly class AllocatedLine
{
    public function __construct(
        public AllocationLine $line,
        public string $theoreticalAmount,
        public int $roundedAmount,
        public int $roundingAdjustment,
        public int $amount,
    ) {}
}
