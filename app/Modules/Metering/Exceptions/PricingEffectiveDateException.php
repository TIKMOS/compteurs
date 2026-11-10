<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

/**
 * Mois d'effet sur une période déjà clôturée (EF-46, D-24, T-47).
 */
final class PricingEffectiveDateException extends DomainException
{
    public function __construct(CarbonImmutable $lastClosedMonth)
    {
        $closed = Str::ucfirst($lastClosedMonth->settings(['locale' => 'fr'])->isoFormat('MMMM YYYY'));
        $first = $lastClosedMonth->addMonthNoOverflow()->settings(['locale' => 'fr'])->isoFormat('MMMM YYYY');

        parent::__construct("{$closed} est clôturé : le nouveau prix peut s'appliquer au plus tôt à {$first}.");
    }
}
