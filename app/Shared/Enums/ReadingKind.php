<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Type d'un relevé de décompteur (CONCEPTION.md, EF-11).
 */
enum ReadingKind: string
{
    case Initial = 'initial';
    case Installation = 'installation';
    case Entry = 'entry';
    case Exit = 'exit';
    case Removal = 'removal';
    case MonthEnd = 'month_end';

    /**
     * Rang d'un relevé à date égale : initial/pose < sortie < entrée < dépose < fin de mois.
     * La sortie passe avant l'entrée pour qu'une passation le même jour donne une tranche vacante nulle.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Initial, self::Installation => 0,
            self::Exit => 1,
            self::Entry => 2,
            self::Removal => 3,
            self::MonthEnd => 4,
        };
    }
}
