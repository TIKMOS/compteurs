<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use App\Shared\Support\Milli;
use DomainException;

final class IndexLowerThanPreviousException extends DomainException
{
    public static function belowPrevious(int $entered, int $previous): self
    {
        return new self(sprintf(
            "L'index saisi (%s) est inférieur à l'index précédent (%s). Si le décompteur a été changé, déclarez un remplacement.",
            Milli::format($entered),
            Milli::format($previous),
        ));
    }

    public static function belowLastReal(int $entered, int $lastReal): self
    {
        return new self(sprintf(
            "L'index saisi (%s) est inférieur au dernier index réel (%s). Si le décompteur a été changé, déclarez un remplacement.",
            Milli::format($entered),
            Milli::format($lastReal),
        ));
    }
}
