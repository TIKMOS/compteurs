<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

/**
 * Entrée ou sortie refusée (EF-07, EF-08) : logement déjà occupé, date incohérente…
 * Le message est destiné au propriétaire.
 */
final class OccupancyRejectedException extends DomainException {}
