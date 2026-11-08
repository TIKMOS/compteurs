<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Enums\CreditMovementKind;
use App\Shared\Support\Fraction;

/**
 * Mouvement sur un lot : imputation sur une tranche, ou solde à la sortie du locataire.
 */
final readonly class CreditMovement
{
    public function __construct(
        public CreditMovementKind $kind,
        public CreditLot $lot,
        public int $quantity,
        public ?Fraction $value = null,
    ) {}
}
