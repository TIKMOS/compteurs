<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\CreditSettlement;
use App\Shared\Support\Fraction;
use InvalidArgumentException;

/**
 * Déduction du crédit restant sur la dernière fiche d'un locataire sortant (EF-24 f). Classe pure.
 *
 * valeur = Σ des lots (électricité + eau), arrondie une seule fois au franc (H-22) ;
 * déduit = min(valeur, total de la fiche) ; à rembourser = valeur − déduit.
 */
final class CreditSettlementCalculator
{
    /**
     * @param int $statementTotal total de la dernière fiche (électricité + eau) avant déduction, en FCFA
     * @param list<Fraction> $creditValues valeurs exactes du crédit soldé, par logement et par fluide
     */
    public function settle(int $statementTotal, array $creditValues): CreditSettlement
    {
        if ($statementTotal < 0) {
            throw new InvalidArgumentException('Le total d\'une fiche ne peut pas être négatif.');
        }

        $value = array_reduce($creditValues, static fn (Fraction $sum, Fraction $v): Fraction => $sum->add($v), Fraction::zero())
            ->roundHalfUp();
        $deducted = min($value, $statementTotal);

        return new CreditSettlement($statementTotal, $value, $deducted, $value - $deducted);
    }
}
