<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

/**
 * La facture du mois manque alors que le mode l'exige : mode 1 ou mode 2 plafonné
 * (EF-31 condition 3, T-41). Le message complet est construit par l'appelant.
 */
final class MissingBillException extends DomainException
{
    public static function make(): self
    {
        return new self('La facture du mois est obligatoire pour ce mode de calcul.');
    }
}
