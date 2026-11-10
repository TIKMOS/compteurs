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
use App\Shared\Support\SafeInt;
use LogicException;

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
            ? new Fraction(SafeInt::mul($bill, 1000), $totalConsumption)
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
            // Mode 1 : facture et consommation vérifiées ci-dessus, le prix réel existe.
            return new ResolvedPrice($rule, $actual, $actual ?? throw new LogicException('Prix réel absent en mode 1.'), PriceSource::Actual);
        }

        $fixed = Fraction::fromDecimal((string) $rule->fixedUnitPrice);

        // Plafonné : facture et consommation vérifiées ci-dessus (requiresBill), $actual n'est pas nul.
        if ($rule->capToActual && $actual !== null && $fixed->compareTo($actual) >= 0) {
            return new ResolvedPrice($rule, $actual, $actual, PriceSource::Actual);
        }

        return new ResolvedPrice($rule, $actual, $fixed, PriceSource::Fixed);
    }
}
