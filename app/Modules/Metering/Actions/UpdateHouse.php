<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\House;
use App\Shared\Enums\Utility;
use Illuminate\Support\Facades\DB;

/**
 * Modifie le nom, l'adresse et les références fournisseur d'une maison (UC-02).
 * La tarification se change à part (ChangeUtilityPricing), avec son historique.
 */
final class UpdateHouse
{
    /**
     * @param array<value-of<Utility>, string|null> $providerReferences
     */
    public function execute(House $house, string $name, ?string $address, array $providerReferences): House
    {
        return DB::transaction(function () use ($house, $name, $address, $providerReferences): House {
            $house->update(['name' => trim($name), 'address' => filled($address) ? trim($address) : null]);

            foreach ($house->mainMeters as $meter) {
                $reference = $providerReferences[$meter->utility->value] ?? null;
                $meter->update(['provider_reference' => filled($reference) ? trim($reference) : null]);
            }

            return $house;
        });
    }
}
