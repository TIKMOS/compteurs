<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Models\SubMeter;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\ReadingKind;
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
        ->and(SubMeter::query()->count())->toBe(7)
        ->and(Tenant::query()->count())->toBe(4)
        ->and(Occupancy::query()->whereNull('ended_on')->count())->toBe(4)
        ->and(MeterReading::query()->where('kind', ReadingKind::Entry)->count())->toBe(7)
        ->and(Occupancy::query()->whereHas('tenant', fn ($q) => $q->where('name', 'M. Hounkpè'))->sole()->started_on->toDateString())
        ->toBe('2026-09-01');

    $this->post('/login', ['login' => '01 97 00 00 00', 'password' => 'password'])->assertRedirect('/maisons');
});
