<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Lecture d'un nombre saisi à la française (« 127,50 », « 1 250,5 ») en décimal normalisé
 * (« 127.50 »), sans jamais passer par un flottant.
 */
final class FrenchDecimal
{
    /**
     * Renvoie le décimal avec $decimals chiffres après le point, ou null si la saisie est
     * invalide ou a plus de $decimals décimales.
     */
    public static function parse(?string $input, int $decimals = 2): ?string
    {
        if ($input === null) {
            return null;
        }

        // Espaces ordinaires, insécables et fines insécables servent de séparateurs de milliers.
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($input));
        $clean = str_replace(',', '.', $clean);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $clean, $m) !== 1 || strlen($m[2] ?? '') > $decimals) {
            return null;
        }

        $integer = ltrim($m[1], '0') ?: '0';

        return $decimals === 0
            ? $integer
            : $integer . '.' . str_pad($m[2] ?? '', $decimals, '0');
    }
}
