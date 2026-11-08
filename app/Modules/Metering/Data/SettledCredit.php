<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Support\Fraction;

/**
 * Crédit restant d'un locataire sortant, pour un logement et un fluide, converti en FCFA (EF-24 f).
 */
final readonly class SettledCredit
{
    public function __construct(
        public int $occupancyId,
        public int $quantity,
        public Fraction $value,
    ) {}
}
