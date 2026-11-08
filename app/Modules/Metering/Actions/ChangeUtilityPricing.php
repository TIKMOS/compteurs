<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\House;
use App\Models\User;
use App\Models\UtilityPricing;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Exceptions\ComplianceAcknowledgementRequiredException;
use App\Modules\Metering\Exceptions\PricingEffectiveDateException;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Change le mode, le prix fixé ou le plafond d'un fluide à partir d'un mois d'effet
 * (UC-22, EF-46, D-18, D-24, CONCEPTION.md § 4.3 « Tarification »).
 *
 * - le mois d'effet est un mois entier, postérieur à la dernière période clôturée ;
 * - une version du même mois qu'aucune clôture n'utilise est corrigée sur place (H-34) ;
 * - activer le mode 2 ou désactiver le plafond exige la confirmation de l'avertissement (EF-48).
 */
final class ChangeUtilityPricing
{
    public function __construct(private readonly RecordComplianceAcknowledgements $acknowledgements) {}

    /**
     * @return UtilityPricing|null la version enregistrée, ou null si rien ne change
     *
     * @throws PricingEffectiveDateException
     * @throws ComplianceAcknowledgementRequiredException
     */
    public function execute(
        House $house,
        Utility $utility,
        PricingRule $rule,
        CarbonImmutable $effectiveFrom,
        User $by,
        bool $complianceConfirmed = false,
        ?string $ipAddress = null,
    ): ?UtilityPricing {
        $effectiveFrom = $effectiveFrom->startOfMonth();

        return DB::transaction(function () use ($house, $utility, $rule, $effectiveFrom, $by, $complianceConfirmed, $ipAddress): ?UtilityPricing {
            // Verrou de la maison : deux changements simultanés ne créent pas deux versions.
            House::query()->whereKey($house->id)->lockForUpdate()->first();
            $house->load('pricings');

            $lastClosed = $house->lastClosedMonth();
            if ($lastClosed !== null && $effectiveFrom->lessThanOrEqualTo($lastClosed)) {
                throw new PricingEffectiveDateException($lastClosed);
            }

            $previous = $house->pricingFor($utility, $effectiveFrom);
            if ($previous !== null && $previous->rule()->equals($rule)) {
                return null;
            }

            $kinds = $rule->acknowledgementsRequiredFrom($previous?->rule());
            if ($kinds !== [] && ! $complianceConfirmed) {
                throw new ComplianceAcknowledgementRequiredException($kinds);
            }

            $attributes = [
                'mode' => $rule->mode,
                'fixed_unit_price' => $rule->fixedUnitPrice,
                'cap_to_actual' => $rule->capToActual,
                'created_by' => $by->id,
            ];

            $sameMonth = $house->pricings->first(
                fn (UtilityPricing $p): bool => $p->utility === $utility && $p->effective_from->equalTo($effectiveFrom),
            );

            if ($sameMonth !== null && ! $sameMonth->isUsedByClosedPeriod()) {
                $sameMonth->update($attributes);
                $version = $sameMonth;
            } else {
                $version = $house->pricings()->create([
                    ...$attributes,
                    'utility' => $utility,
                    'effective_from' => $effectiveFrom,
                ]);
            }

            $this->acknowledgements->record($version, $kinds, $by, $ipAddress);
            $house->unsetRelation('pricings');

            return $version;
        });
    }
}
