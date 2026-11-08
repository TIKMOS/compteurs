<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

/**
 * Une ligne à répartir : un payeur (occupation ou vacant) d'un logement, pour un fluide.
 */
final readonly class AllocationLine
{
    /**
     * @param string $key identifiant libre du payeur (ex. « unit:3|occ:12 », « unit:3|vacant »)
     * @param int $consumption consommation facturée en millièmes (≥ 0)
     * @param string|null $startedOn date d'entrée Y-m-d, départage les égalités (H-5) ; null = vacant
     */
    public function __construct(
        public string $key,
        public string $unitLabel,
        public int $consumption,
        public ?string $startedOn = null,
    ) {}
}
