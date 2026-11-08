<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Moyen de paiement d'une participation (UC-18) : espèces, MTN MoMo, Moov Money, autre.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MtnMomo = 'mtn_momo';
    case MoovMoney = 'moov_money';
    case Other = 'other';

    /** Ancienne valeur (lot 2, tranche 3), sans opérateur : affichée, plus proposée (H-73). */
    case MobileMoney = 'mobile_money';

    /**
     * Moyens proposés à la saisie.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Cash, self::MtnMomo, self::MoovMoney, self::Other];
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MtnMomo => 'MTN MoMo',
            self::MoovMoney => 'Moov Money',
            self::Other => 'Autre',
            self::MobileMoney => 'Mobile Money',
        };
    }
}
