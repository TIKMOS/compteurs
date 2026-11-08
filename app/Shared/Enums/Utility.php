<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum Utility: string
{
    case Electricity = 'electricity';
    case Water = 'water';

    public function unit(): string
    {
        return match ($this) {
            self::Electricity => 'kWh',
            self::Water => 'm³',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'électricité',
            self::Water => 'eau',
        };
    }
}
