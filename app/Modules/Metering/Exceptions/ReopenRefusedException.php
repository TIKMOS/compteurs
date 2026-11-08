<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

/**
 * Réouverture refusée (EF-34, H-29) : ce n'est pas le dernier mois clôturé, le mois suivant a
 * déjà des relevés, ou un remboursement de crédit du mois est déjà effectué.
 */
final class ReopenRefusedException extends DomainException {}
