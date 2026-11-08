<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

/**
 * Σ consommations ≤ 0 : la facture ne peut pas être répartie, la clôture est bloquée (EF-31).
 * Le message complet (fluide, mois, montant) est construit par l'Action de clôture.
 */
final class ZeroConsumptionException extends DomainException
{
    public static function forTotal(int $totalAmount): self
    {
        return new self(sprintf(
            'La somme des consommations relevées est nulle : la facture de %s FCFA ne peut pas être répartie. Vérifiez les index saisis.',
            number_format($totalAmount, 0, ',', "\u{00A0}"),
        ));
    }
}
