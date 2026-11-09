<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\User;

// Revue avant déploiement : limitation des tentatives, vérification de l'email, mot de passe.

test('après 5 échecs, la connexion est bloquée pour ce compte et cette adresse IP', function (): void {
    $user = User::factory()->create(['email' => 'proprio@example.com']);

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['login' => 'proprio@example.com', 'password' => 'mauvais'])->assertSessionHasErrors('login');
    }

    $this->post('/login', ['login' => 'proprio@example.com', 'password' => 'password'])->assertStatus(429);
    $this->assertGuest();
});

test('un compte dont l\'email n\'est pas vérifié n\'accède à aucune donnée', function (): void {
    $user = User::factory()->unverified()->create();
    $house = House::factory()->for($user, 'owner')->create();

    $this->actingAs($user)->get('/maisons')->assertRedirect('/email/verify');
    $this->actingAs($user)->get("/maisons/{$house->getRouteKey()}")->assertRedirect('/email/verify');
});

test('inscription : mot de passe trop court ou non confirmé refusé', function (): void {
    $this->post('/register', [
        'name' => 'Test', 'email' => 'nouveau@example.com', 'password' => 'court', 'password_confirmation' => 'court', 'terms' => '1',
    ])->assertSessionHasErrors('password');
    $this->assertGuest();
});
