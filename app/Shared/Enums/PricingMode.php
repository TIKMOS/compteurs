<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Mode de calcul d'un fluide pour une maison (D-16, EF-39).
 */
enum PricingMode: string
{
    /** Mode 1 : prix unitaire = facture ÷ Σ consommations (par défaut). */
    case BillSplit = 'bill_split';

    /** Mode 2 : prix fixé par le propriétaire, plafonné ou non au prix réel. */
    case FixedPrice = 'fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::BillSplit => 'Répartition de la facture',
            self::FixedPrice => 'Prix fixé par le propriétaire',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BillSplit => 'Le montant de la facture est réparti selon la consommation de chaque logement.',
            self::FixedPrice => 'Vous fixez un prix par unité ; chaque locataire participe selon sa consommation.',
        };
    }
}
