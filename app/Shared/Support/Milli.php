<?php

declare(strict_types=1);

namespace App\Shared\Support;

use InvalidArgumentException;

/**
 * Index, consommations et crédits sont manipulés en millièmes entiers
 * (colonnes DECIMAL(12,3)) : aucun calcul en flottant (ENF-07).
 */
final class Milli
{
    public static function fromDecimal(string|int $value): int
    {
        $value = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], (string) $value);

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,3}))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("Valeur décimale invalide : « {$value} » (3 décimales au plus).");
        }

        $milli = (int) $m[2] * 1000 + (int) str_pad($m[3] ?? '', 3, '0');

        return $m[1] === '-' ? -$milli : $milli;
    }

    /** Format base de données : « 1534.500 ». */
    public static function toDecimal(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, 1000), $abs % 1000);
    }

    /** Format d'affichage français : « 1 534 », « 199,5 », « 94,667 ». */
    public static function format(int $milli): string
    {
        $sign = $milli < 0 ? '−' : '';
        $abs = abs($milli);
        $integer = number_format(intdiv($abs, 1000), 0, ',', "\u{00A0}");
        $decimals = rtrim(sprintf('%03d', $abs % 1000), '0');

        return $sign . $integer . ($decimals === '' ? '' : ',' . $decimals);
    }

    /** Arrondi au plus proche d'une fraction positive (0,5 vers le haut). */
    public static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('roundHalfUp attend une fraction positive.');
        }

        return intdiv(SafeInt::add(SafeInt::mul(2, $numerator), $denominator), SafeInt::mul(2, $denominator));
    }

    /** Fraction irréductible « a/b », ou « a » si b = 1. */
    public static function fraction(int $numerator, int $denominator): string
    {
        $gcd = self::gcd(abs($numerator), abs($denominator));
        $gcd = $gcd === 0 ? 1 : $gcd;
        $n = intdiv($numerator, $gcd);
        $d = intdiv($denominator, $gcd);

        return $d === 1 ? (string) $n : "{$n}/{$d}";
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
