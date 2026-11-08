<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

/**
 * Clôture impossible (EF-31) : la liste des manques est destinée au propriétaire.
 */
final class IncompletePeriodException extends DomainException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
