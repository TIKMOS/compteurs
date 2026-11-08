<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Support\Fraction;

/**
 * Qui a payé une tranche estimée, et à quel prix : nécessaire pour créer le lot de crédit
 * si le relevé réel suivant révèle une surestimation (EF-24 b). Connu une fois le mois clôturé.
 */
final readonly class EstimateOrigin
{
    /**
     * @param int|null $occupancyId payeur de la tranche estimée (null = propriétaire / vacant)
     * @param Fraction $unitPrice prix unitaire du mois surestimé (FCFA par kWh ou m³)
     * @param string $month mois surestimé, « 2026-11 »
     */
    public function __construct(
        public ?int $occupancyId,
        public Fraction $unitPrice,
        public string $month,
    ) {}
}
