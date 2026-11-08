<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\House;
use App\Models\User;
use App\Modules\Metering\Actions\CreateHouse;
use App\Modules\Metering\Actions\CreateUnit;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Data\SubMeterInstallation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration, rejouables sans doublon : un propriétaire et la maison
 * « Cotonou Fidjrossè » de CONCEPTION.md § 5.
 *
 * 4 logements et leurs décompteurs (tranche 2). À compléter : locataires (tranche 3),
 * 3 mois de relevés et de factures (tranche 4).
 *
 *   php artisan db:seed --class=DemoSeeder
 *   Connexion : demo@compteurs.test (ou 01 97 00 00 00) / password
 */
final class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@compteurs.test';

    public function run(CreateHouse $createHouse): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Propriétaire de démonstration',
                'phone' => '+2290197000000',
                'password' => Hash::make('password'),
            ],
        );
        $owner->forceFill(['email_verified_at' => $owner->email_verified_at ?? now()])->save();

        $house = House::withTrashed()->ownedBy($owner)->where('name', 'Cotonou Fidjrossè')->first()
            ?? $createHouse->execute(
                owner: $owner,
                name: 'Cotonou Fidjrossè',
                address: 'Fidjrossè, Cotonou — derrière la pharmacie',
                providerReferences: ['electricity' => 'SBEE 0412-7781', 'water' => 'SONEB 33-10598'],
                pricing: ['electricity' => PricingRule::billSplit(), 'water' => PricingRule::billSplit()],
            );

        $this->seedUnits($house);
    }

    /**
     * 4 logements, décompteurs posés le 01/08/2026 : les index de pose mènent aux index de
     * début d'octobre de CONCEPTION.md § 5.2 après les consommations d'août et de septembre.
     * La boutique n'a pas de décompteur d'eau (EF-05).
     */
    private function seedUnits(House $house): void
    {
        $installedOn = CarbonImmutable::parse('2026-08-01');
        $units = [
            'Chambre 1' => ['electricity' => '966', 'water' => '394'],
            'Chambre 2' => ['electricity' => '3290', 'water' => '174'],
            'Chambre 3' => ['electricity' => '287', 'water' => '932'],
            'Boutique' => ['electricity' => '5000', 'water' => null],
        ];

        foreach ($units as $label => $indexes) {
            if ($house->units()->withTrashed()->where('label', $label)->exists()) {
                continue;
            }

            app(CreateUnit::class)->execute($house, $label, array_map(
                fn (?string $index): ?SubMeterInstallation => $index === null
                    ? null
                    : new SubMeterInstallation($installedOn, $index . '.000'),
                $indexes,
            ));
        }
    }
}
