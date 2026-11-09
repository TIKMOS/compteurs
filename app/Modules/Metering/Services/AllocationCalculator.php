<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\AllocatedLine;
use App\Modules\Metering\Data\AllocationLine;
use App\Modules\Metering\Data\AllocationResult;
use App\Modules\Metering\Exceptions\ZeroConsumptionException;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;
use App\Shared\Support\SafeInt;
use InvalidArgumentException;

/**
 * Répartition d'une facture (EF-26 à EF-29). Classe pure, arithmétique entière exacte.
 *
 * prix unitaire = facture ÷ Σ consommations ; montant = consommation × prix unitaire,
 * arrondi au franc (0,5 vers le haut) ; écart d'arrondi au plus gros consommateur.
 */
final class AllocationCalculator
{
    /**
     * Plus gros consommateur d'abord ; à égalité, plus petit libellé (ordre naturel),
     * puis occupant entré le premier, la ligne vacante en dernier (H-5).
     */
    private static function compareForRoundingGap(AllocationLine $a, AllocationLine $b): int
    {
        return [$b->consumption, 0, $a->startedOn === null ? 1 : 0, $a->startedOn ?? '']
            <=> [$a->consumption, strnatcasecmp($b->unitLabel, $a->unitLabel), $b->startedOn === null ? 1 : 0, $b->startedOn ?? ''];
    }

    /**
     * @param list<AllocationLine> $lines consommations facturées en millièmes
     */
    public function allocate(int $totalAmount, array $lines): AllocationResult
    {
        if ($totalAmount < 0) {
            throw new InvalidArgumentException('Le montant de la facture ne peut pas être négatif.');
        }

        $totalConsumption = 0;
        foreach ($lines as $line) {
            if ($line->consumption < 0) {
                throw new InvalidArgumentException('Une consommation facturée ne peut pas être négative.');
            }
            $totalConsumption += $line->consumption;
        }

        if ($totalConsumption <= 0) {
            throw ZeroConsumptionException::forTotal($totalAmount);
        }

        $rounded = [];
        $theoretical = [];
        foreach ($lines as $line) {
            $numerator = SafeInt::mul($totalAmount, $line->consumption);
            $theoretical[$line->key] = Milli::fraction($numerator, $totalConsumption);
            $rounded[$line->key] = Milli::roundHalfUp($numerator, $totalConsumption);
        }

        $adjustments = $this->distributeGap($totalAmount - array_sum($rounded), $lines, $rounded);

        $allocated = [];
        foreach ($lines as $line) {
            $adjustment = $adjustments[$line->key] ?? 0;
            $allocated[$line->key] = new AllocatedLine(
                $line,
                $theoretical[$line->key],
                $rounded[$line->key],
                $adjustment,
                $rounded[$line->key] + $adjustment,
            );
        }

        return new AllocationResult(
            $totalAmount,
            $totalConsumption,
            Milli::fraction(SafeInt::mul($totalAmount, 1000), $totalConsumption),
            $allocated,
        );
    }

    /**
     * Mode 2, prix fixé appliqué (EF-40, EF-41) : montant = consommation × prix, arrondi au franc
     * pour chaque ligne, sans écart d'arrondi à répartir. Avec un plafond (la facture, mode 2
     * plafonné), un excédent dû aux seuls arrondis est retiré selon l'ordre d'EF-29 (H-31).
     *
     * @param list<AllocationLine> $lines consommations facturées en millièmes
     */
    public function allocateAtPrice(Fraction $price, array $lines, ?int $cap = null): AllocationResult
    {
        $totalConsumption = 0;
        $rounded = [];
        $theoretical = [];

        foreach ($lines as $line) {
            if ($line->consumption < 0) {
                throw new InvalidArgumentException('Une consommation facturée ne peut pas être négative.');
            }
            $totalConsumption += $line->consumption;

            $numerator = SafeInt::mul($price->numerator, $line->consumption);
            $denominator = SafeInt::mul($price->denominator, 1000);
            $theoretical[$line->key] = Milli::fraction($numerator, $denominator);
            $rounded[$line->key] = Milli::roundHalfUp($numerator, $denominator);
        }

        $sum = array_sum($rounded);
        $adjustments = $cap !== null && $sum > $cap
            ? $this->distributeGap($cap - $sum, $lines, $rounded)
            : [];

        $allocated = [];
        foreach ($lines as $line) {
            $adjustment = $adjustments[$line->key] ?? 0;
            $allocated[$line->key] = new AllocatedLine(
                $line,
                $theoretical[$line->key],
                $rounded[$line->key],
                $adjustment,
                $rounded[$line->key] + $adjustment,
            );
        }

        return new AllocationResult(
            $cap ?? $sum + array_sum($adjustments),
            $totalConsumption,
            (string) $price,
            $allocated,
        );
    }

    /**
     * L'écart va au plus gros consommateur ; un écart négatif qui rendrait son montant négatif
     * déborde sur le suivant (factures de quelques francs, T-28).
     *
     * @param list<AllocationLine> $lines
     * @param array<string, int> $rounded
     *
     * @return array<string, int>
     */
    private function distributeGap(int $gap, array $lines, array $rounded): array
    {
        if ($gap === 0) {
            return [];
        }

        $ordered = $lines;
        usort($ordered, self::compareForRoundingGap(...));

        if ($gap > 0) {
            return [$ordered[0]->key => $gap];
        }

        $adjustments = [];
        $remaining = -$gap;
        foreach ($ordered as $line) {
            $take = min($remaining, $rounded[$line->key]);
            if ($take > 0) {
                $adjustments[$line->key] = -$take;
                $remaining -= $take;
            }
            if ($remaining === 0) {
                break;
            }
        }

        return $adjustments;
    }
}
