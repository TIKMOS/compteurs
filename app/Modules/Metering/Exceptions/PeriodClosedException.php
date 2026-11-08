<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use App\Models\BillingPeriod;
use DomainException;
use Illuminate\Support\Str;

/**
 * Une période clôturée refuse toute écriture (CONCEPTION.md § 4.3).
 */
final class PeriodClosedException extends DomainException
{
    public function __construct(BillingPeriod $period)
    {
        parent::__construct(Str::ucfirst($period->label()) . ' est clôturé : il ne peut plus être modifié.');
    }
}
