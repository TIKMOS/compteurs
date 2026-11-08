<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ChargeKind: string
{
    case Tenant = 'tenant';
    case Vacant = 'vacant';
}
