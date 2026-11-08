<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

final class EstimationImpossibleException extends DomainException
{
    public static function noHistory(): self
    {
        return new self("Pas d'historique pour estimer ce décompteur : saisissez l'index réel.");
    }
}
