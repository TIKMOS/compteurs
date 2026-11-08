<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\House;
use App\Models\User;
use App\Modules\Metering\Actions\CreateHouse;
use App\Modules\Metering\Data\PricingRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration, rejouables sans doublon : un propriétaire et la maison
 * « Cotonou Fidjrossè » de CONCEPTION.md § 5.
 *
 * Complété au fil du lot 1 : 4 logements (tranche 2), locataires (tranche 3),
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

        $exists = House::withTrashed()->ownedBy($owner)->where('name', 'Cotonou Fidjrossè')->exists();
        if (! $exists) {
            $createHouse->execute(
                owner: $owner,
                name: 'Cotonou Fidjrossè',
                address: 'Fidjrossè, Cotonou — derrière la pharmacie',
                providerReferences: ['electricity' => 'SBEE 0412-7781', 'water' => 'SONEB 33-10598'],
                pricing: ['electricity' => PricingRule::billSplit(), 'water' => PricingRule::billSplit()],
            );
        }
    }
}
