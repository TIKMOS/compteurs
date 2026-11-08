<?php

declare(strict_types=1);

namespace App\Modules\Metering\Services;

use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Modules\Metering\Exceptions\MissingBillException;
use App\Modules\Metering\Exceptions\ZeroConsumptionException;
use App\Shared\Enums\PriceSource;
use App\Shared\Enums\PricingMode;
use App\Shared\Support\Fraction;

/**
 * Prix appliqué d'un fluide pour un mois (EF-26, EF-41, EF-43, EF-44, D-23). Classe pure.
 *
 * - mode 1 : prix réel = facture ÷ Σ consommations ; facture obligatoire, Σ > 0 ;
 * - mode 2 plafonné : facture obligatoire, Σ > 0 ; prix réel si prix fixé ≥ prix réel (D-23),
 *   sinon prix fixé ;
 * - mode 2 non plafonné : prix fixé ; facture facultative, Σ = 0 permise.
 */
final class UnitPriceResolver
{
    /**
     * @param int|null $bill montant de la facture en FCFA, null si non saisie
     * @param int $totalConsumption Σ des consommations facturées, en millièmes
     *
     * @throws MissingBillException|ZeroConsumptionException
     */
    public function resolve(PricingRule $rule, ?int $bill, int $totalConsumption): ResolvedPrice
    {
        $actual = $bill !== null && $totalConsumption > 0
            ? new Fraction($bill * 1000, $totalConsumption)
            : null;

        if ($rule->requiresBill()) {
            if ($bill === null) {
                throw MissingBillException::make();
            }
            if ($totalConsumption <= 0) {
                throw ZeroConsumptionException::forTotal($bill);
            }
        }

        if ($rule->mode === PricingMode::BillSplit) {
            return new ResolvedPrice($rule, $actual, $actual, PriceSource::Actual);
        }

        $fixed = Fraction::fromDecimal((string) $rule->fixedUnitPrice);

        if ($rule->capToActual && $fixed->compareTo($actual) >= 0) {
            return new ResolvedPrice($rule, $actual, $actual, PriceSource::Actual);
        }

        return new ResolvedPrice($rule, $actual, $fixed, PriceSource::Fixed);
    }
}
