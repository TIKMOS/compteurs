<?php

declare(strict_types=1);

namespace App\Shared\Support;

use InvalidArgumentException;

/**
 * Nombre entier en toutes lettres, orthographe traditionnelle (montants des fiches) :
 * « dix-neuf mille trois cent un », « quatre-vingts », « deux cents », « deux cent mille ».
 * Pur PHP : ne dépend pas de l'extension intl.
 */
final class FrenchNumber
{
    private const UNITS = [
        'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf',
    ];

    private const TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    public static function toWords(int $n): string
    {
        if ($n < 0) {
            return 'moins ' . self::toWords(-$n);
        }
        if ($n === 0) {
            return 'zéro';
        }
        if ($n >= 1_000_000_000_000) {
            throw new InvalidArgumentException('Montant trop grand.');
        }

        $parts = [];
        foreach ([[1_000_000_000, 'milliard'], [1_000_000, 'million']] as [$size, $noun]) {
            $count = intdiv($n, $size);
            $n %= $size;
            if ($count > 0) {
                // Million et milliard sont des noms : « deux cents millions », pluriel normal.
                $parts[] = self::belowThousand($count, true) . ' ' . $noun . ($count > 1 ? 's' : '');
            }
        }

        $thousands = intdiv($n, 1000);
        $n %= 1000;
        if ($thousands > 0) {
            // « mille » invariable, jamais « un mille » ; « cent » et « vingt » ne prennent pas de s devant.
            $parts[] = $thousands === 1 ? 'mille' : self::belowThousand($thousands, false) . ' mille';
        }
        if ($n > 0) {
            $parts[] = self::belowThousand($n, true);
        }

        return implode(' ', $parts);
    }

    /**
     * @param bool $final vrai si rien ne suit sauf un nom (million, milliard) : autorise « cents » et « vingts »
     */
    private static function belowThousand(int $n, bool $final): string
    {
        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        $words = [];

        if ($hundreds > 0) {
            $plural = $hundreds > 1 && $rest === 0 && $final;
            $words[] = ($hundreds > 1 ? self::UNITS[$hundreds] . ' ' : '') . 'cent' . ($plural ? 's' : '');
        }
        if ($rest > 0) {
            $words[] = self::belowHundred($rest, $final);
        }

        return implode(' ', $words);
    }

    private static function belowHundred(int $n, bool $final): string
    {
        if ($n < 20) {
            return self::UNITS[$n];
        }

        $ten = intdiv($n, 10);
        $unit = $n % 10;

        // 70-79 et 90-99 : soixante-dix, quatre-vingt-dix…
        if ($ten === 7 || $ten === 9) {
            $base = $ten === 7 ? 'soixante' : 'quatre-vingt';
            $teen = self::UNITS[10 + $unit];

            return $base . ($ten === 7 && $unit === 1 ? ' et ' : '-') . $teen;
        }

        if ($ten === 8) {
            return $unit === 0 ? 'quatre-vingt' . ($final ? 's' : '') : 'quatre-vingt-' . self::UNITS[$unit];
        }

        $base = self::TENS[$ten] ?? throw new InvalidArgumentException("belowHundred attend un nombre de 0 à 99, reçu {$n}.");

        return match (true) {
            $unit === 0 => $base,
            $unit === 1 => $base . ' et un',
            default => $base . '-' . self::UNITS[$unit],
        };
    }
}
