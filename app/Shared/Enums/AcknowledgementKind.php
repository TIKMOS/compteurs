<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Événement qui a exigé la confirmation de l'avertissement de conformité (EF-48).
 */
enum AcknowledgementKind: string
{
    case FixedPriceEnabled = 'fixed_price_enabled';
    case CapDisabled = 'cap_disabled';

    public function label(): string
    {
        return match ($this) {
            self::FixedPriceEnabled => 'Activation du prix fixé',
            self::CapDisabled => 'Désactivation du plafond au prix réel',
        };
    }
}
