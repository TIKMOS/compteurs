<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\House;
use App\Models\User;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Exceptions\ComplianceAcknowledgementRequiredException;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Crée une maison avec ses deux compteurs principaux et la tarification initiale de chaque
 * fluide (UC-02, EF-01, EF-02, EF-39). Si un fluide est créé en mode 2, l'avertissement de
 * conformité doit avoir été confirmé ; les confirmations sont enregistrées (EF-48, EF-49).
 */
final class CreateHouse
{
    public function __construct(private readonly RecordComplianceAcknowledgements $acknowledgements) {}

    /**
     * @param array<value-of<Utility>, string|null> $providerReferences n° SBEE / n° de police SONEB
     * @param array<value-of<Utility>, PricingRule> $pricing règle par fluide (mode 1 si absente)
     *
     * @throws ComplianceAcknowledgementRequiredException
     */
    public function execute(
        User $owner,
        string $name,
        ?string $address,
        array $providerReferences,
        array $pricing,
        bool $complianceConfirmed = false,
        ?string $ipAddress = null,
    ): House {
        $rules = [];
        $required = [];

        foreach (Utility::cases() as $utility) {
            $rules[$utility->value] = $pricing[$utility->value] ?? PricingRule::billSplit();
            array_push($required, ...$rules[$utility->value]->acknowledgementsRequiredFrom(null));
        }

        if ($required !== [] && ! $complianceConfirmed) {
            throw new ComplianceAcknowledgementRequiredException(array_values(array_unique($required, SORT_REGULAR)));
        }

        return DB::transaction(function () use ($owner, $name, $address, $providerReferences, $rules, $ipAddress): House {
            $house = new House(['name' => trim($name), 'address' => filled($address) ? trim($address) : null]);
            $house->owner()->associate($owner);
            $house->save();

            $month = CarbonImmutable::now()->startOfMonth();

            foreach (Utility::cases() as $utility) {
                $reference = $providerReferences[$utility->value] ?? null;

                $house->mainMeters()->create([
                    'utility' => $utility,
                    'provider_reference' => filled($reference) ? trim($reference) : null,
                ]);

                $rule = $rules[$utility->value];
                $version = $house->pricings()->create([
                    'utility' => $utility,
                    'mode' => $rule->mode,
                    'fixed_unit_price' => $rule->fixedUnitPrice,
                    'cap_to_actual' => $rule->capToActual,
                    'effective_from' => $month,
                    'created_by' => $owner->id,
                ]);

                $this->acknowledgements->record($version, $rule->acknowledgementsRequiredFrom(null), $owner, $ipAddress);
            }

            return $house;
        });
    }
}
