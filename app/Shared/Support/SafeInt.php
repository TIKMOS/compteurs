<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Arithmétique entière contrôlée (ENF-07 : aucun flottant). En PHP, un produit ou une somme qui
 * dépasse PHP_INT_MAX devient silencieusement un flottant : on vérifie avant de calculer et on
 * lève ValueTooLargeException, pour que l'écran affiche un message clair au lieu d'une erreur 500
 * (revue I-3).
 */
final class SafeInt
{
    public static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a === PHP_INT_MIN || $b === PHP_INT_MIN || abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new ValueTooLargeException;
        }

        return $a * $b;
    }

    public static function add(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new ValueTooLargeException;
        }

        return $a + $b;
    }
}
