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

test('M-2 un même numéro écrit de plusieurs façons compte comme un seul compte', function (): void {
    User::factory()->create(['phone' => '+2290197000000']);

    foreach (['01 97 00 00 00', '0197000000', '+229 01 97 00 00 00', '01.97.00.00.00', '+2290197000000'] as $written) {
        $this->post('/login', ['login' => $written, 'password' => 'mauvais'])->assertSessionHasErrors('login');
    }

    $this->post('/login', ['login' => '01 97 00 00 00', 'password' => 'password'])->assertStatus(429);
});

test('M-2 au-delà de 20 essais par minute depuis une même adresse IP, tous comptes confondus : bloqué', function (): void {
    foreach (range(1, 20) as $i) {
        $this->post('/login', ['login' => "inconnu{$i}@example.com", 'password' => 'x'])->assertSessionHasErrors('login');
    }

    $this->post('/login', ['login' => 'encore@example.com', 'password' => 'x'])->assertStatus(429);
});

test('un compte dont l\'email n\'est pas vérifié n\'accède à aucune donnée', function (): void {
    $user = User::factory()->unverified()->create();
    $house = House::factory()->for($user, 'owner')->create();

    $this->actingAs($user)->get('/maisons')->assertRedirect('/email/verify');
    $this->actingAs($user)->get("/maisons/{$house->getRouteKey()}")->assertRedirect('/email/verify');
});

test('M-1 production : un mot de passe divulgué dans une fuite est refusé, un autre accepté', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    // « Have I Been Pwned » simulé : le suffixe du hachage de « motdepasse-fuite » y figure.
    $leaked = strtoupper(sha1('motdepasse-fuite'));
    Illuminate\Support\Facades\Http::fake([
        'api.pwnedpasswords.com/range/*' => Illuminate\Support\Facades\Http::response(substr($leaked, 5) . ":1234\r\n0000000000000000000000000000000000A:1"),
    ]);
    $register = fn (string $password) => $this->withHeaders(['X-Forwarded-Proto' => 'https', 'Sec-Fetch-Site' => 'same-origin'])->post('https://localhost/register', [
        'name' => 'Test', 'email' => 'nouveau@example.com', 'password' => $password, 'password_confirmation' => $password, 'terms' => '1',
    ]);

    $register('motdepasse-fuite')->assertSessionHasErrors(['password' => 'Ce mot de passe figure dans une fuite de données connue : choisissez-en un autre.']);
    $this->assertGuest();

    $register('ma phrase de passe à moi')->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('inscription : la page indique la règle du mot de passe', function (): void {
    $this->get('/register')->assertOk()->assertSee('8 caractères au moins');
});

test('inscription : mot de passe trop court ou non confirmé refusé', function (): void {
    $this->post('/register', [
        'name' => 'Test', 'email' => 'nouveau@example.com', 'password' => 'court', 'password_confirmation' => 'court', 'terms' => '1',
    ])->assertSessionHasErrors('password');
    $this->assertGuest();
});
