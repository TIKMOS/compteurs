<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use Carbon\CarbonImmutable;

/**
 * Pose d'un décompteur (EF-04) : numéro de série facultatif, date et index de pose
 * (décimal à 3 chiffres au plus, « 1250.000 »).
 */
final readonly class SubMeterInstallation
{
    public function __construct(
        public CarbonImmutable $installedOn,
        public string $installIndex,
        public ?string $serialNumber = null,
    ) {}
}
