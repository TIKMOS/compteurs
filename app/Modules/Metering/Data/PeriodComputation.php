<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Models\BillingPeriod;
use App\Models\UtilityPricing;
use App\Shared\Enums\Utility;

/**
 * Calcul d'un mois en brouillon, sans écriture (UC-13) : par fluide, la répartition ou la
 * raison qui l'empêche ; la liste des manques qui bloquent la clôture (EF-31) ; les soldes de
 * crédit des locataires sortants (EF-24 f).
 */
final readonly class PeriodComputation
{
    /**
     * @param array<value-of<Utility>, UtilityAllocation> $allocations fluides calculables
     * @param array<value-of<Utility>, UtilityPricing> $pricings version de tarification du mois
     * @param array<value-of<Utility>, int|null> $bills montant de la facture saisie
     * @param list<string> $problems manques, en français (vide = clôture possible)
     * @param array<int, CreditSettlement> $settlements par occupation sortante
     * @param array<int, string> $occupantNames nom du locataire par occupation
     */
    public function __construct(
        public BillingPeriod $period,
        public array $allocations,
        public array $pricings,
        public array $bills,
        public array $problems,
        public array $settlements,
        public array $occupantNames,
    ) {}

    public function canClose(): bool
    {
        return $this->problems === [] && ! $this->period->isClosed();
    }
}
