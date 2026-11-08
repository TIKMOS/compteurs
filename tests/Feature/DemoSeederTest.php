<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\SubMeter;
use App\Models\User;
use App\Shared\Enums\Utility;
use Database\Seeders\DemoSeeder;

test('le seeder de démonstration crée le propriétaire et sa maison, sans doublon', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $owner = User::query()->where('email', DemoSeeder::EMAIL)->sole();
    expect($owner->hasVerifiedEmail())->toBeTrue()
        ->and(House::query()->ownedBy($owner)->pluck('name')->all())->toBe(['Cotonou Fidjrossè'])
        ->and(House::query()->sole()->mainMeters)->toHaveCount(2);

    $units = House::query()->sole()->units()->with('subMeters')->get()->keyBy('label');
    expect($units->keys()->sort()->values()->all())->toBe(['Boutique', 'Chambre 1', 'Chambre 2', 'Chambre 3'])
        ->and($units['Chambre 1']->activeSubMeter(Utility::Electricity)->install_index)->toBe('966.000')
        ->and($units['Boutique']->activeSubMeter(Utility::Water))->toBeNull()
        ->and(SubMeter::query()->count())->toBe(7);

    $this->post('/login', ['login' => '01 97 00 00 00', 'password' => 'password'])->assertRedirect('/maisons');
});
