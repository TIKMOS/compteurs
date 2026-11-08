<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CreditMovementKind: string
{
    /** Crédit déduit de la consommation d'une tranche du même payeur. */
    case Applied = 'applied';

    /** Crédit restant converti en FCFA à la sortie du locataire. */
    case Settled = 'settled';
}
