<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use App\Shared\Enums\AcknowledgementKind;
use DomainException;

/**
 * Le changement demandé active le mode 2 ou désactive le plafond : l'avertissement de
 * conformité doit d'abord être confirmé (EF-48).
 */
final class ComplianceAcknowledgementRequiredException extends DomainException
{
    /**
     * @param list<AcknowledgementKind> $kinds
     */
    public function __construct(public readonly array $kinds)
    {
        parent::__construct('La confirmation de l\'avertissement de conformité est requise.');
    }
}
