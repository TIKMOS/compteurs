<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\User;
use Database\Seeders\DemoSeeder;

test('le seeder de démonstration crée le propriétaire et sa maison, sans doublon', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $owner = User::query()->where('email', DemoSeeder::EMAIL)->sole();
    expect($owner->hasVerifiedEmail())->toBeTrue()
        ->and(House::query()->ownedBy($owner)->pluck('name')->all())->toBe(['Cotonou Fidjrossè'])
        ->and(House::query()->sole()->mainMeters)->toHaveCount(2);

    $this->post('/login', ['login' => '01 97 00 00 00', 'password' => 'password'])->assertRedirect('/maisons');
});
