<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PeriodStatus: string
{
    case Draft = 'draft';
    case Closed = 'closed';
}
