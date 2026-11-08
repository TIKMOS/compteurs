<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use DateTimeImmutable;

final readonly class OccupancySpan
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $startedOn,
        public ?DateTimeImmutable $endedOn = null,
    ) {}

    public function isActiveOn(DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->startedOn->format('Y-m-d') <= $day
            && ($this->endedOn === null || $this->endedOn->format('Y-m-d') >= $day);
    }
}
