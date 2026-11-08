<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Numéro de téléphone béninois au format international +229 (EF-06).
 *
 * Depuis 2024, les numéros comptent 10 chiffres et commencent par 01. Un ancien numéro à
 * 8 chiffres est accepté et complété par 01, comme l'a fait la migration nationale.
 */
final class BeninPhone
{
    private const PREFIX = '+229';

    /**
     * Renvoie le numéro normalisé (« +2290197000000 »), ou null s'il n'est pas béninois.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '00229')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '229') && strlen($digits) > 10) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) === 8) {
            $digits = '01' . $digits;
        }

        if (preg_match('/^01\d{8}$/', $digits) !== 1) {
            return null;
        }

        return self::PREFIX . $digits;
    }
}
