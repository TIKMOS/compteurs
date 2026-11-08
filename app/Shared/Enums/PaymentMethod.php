<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Moyen de paiement d'une participation (UC-18).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MobileMoney => 'Mobile Money',
            self::Other => 'Autre',
        };
    }
}
