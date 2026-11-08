<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use App\Shared\Enums\Utility;
use DomainException;

/**
 * Un logement a déjà un décompteur actif pour ce fluide (EF-04) : pour en changer,
 * il faut déclarer un remplacement (EF-18).
 */
final class ActiveSubMeterExistsException extends DomainException
{
    public function __construct(Utility $utility)
    {
        parent::__construct("Ce logement a déjà un décompteur d'{$utility->label()}. Pour le changer, déclarez un remplacement.");
    }
}
