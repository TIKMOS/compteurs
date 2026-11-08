<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use App\Shared\Enums\Utility;
use DomainException;

/**
 * Index refusé sur le décompteur d'un fluide (EF-17) : l'écran affiche le message sous le
 * champ de ce fluide.
 */
final class ReadingRejectedException extends DomainException
{
    public function __construct(public readonly Utility $utility, string $message)
    {
        parent::__construct($message);
    }
}
