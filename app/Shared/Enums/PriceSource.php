<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Origine du prix appliqué à un fluide pour un mois (EF-42).
 */
enum PriceSource: string
{
    /** Prix réel = facture ÷ Σ consommations (mode 1, ou mode 2 plafonné quand le plafond joue, D-23). */
    case Actual = 'actual';

    /** Prix fixé par le propriétaire (mode 2). */
    case Fixed = 'fixed';
}
