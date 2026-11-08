<?php

declare(strict_types=1);

use App\Models\User;

// UC-01 : inscription et connexion par email ou par téléphone (Fortify + écrans d'artisandubenin).

test('la page de connexion s\'affiche en français', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Connexion')
        ->assertSee('Se connecter')
        ->assertSee('Mot de passe oublié');
});

test('la page d\'inscription s\'affiche', function (): void {
    $this->get('/register')->assertOk()->assertSee('Créer mon compte propriétaire');
});

test('l\'accueil mène aux maisons, et un invité est renvoyé vers la connexion', function (): void {
    $this->get('/')->assertRedirect('/maisons');
    $this->get('/maisons')->assertRedirect('/login');
});

test('connexion par email', function (): void {
    $user = User::factory()->create(['email' => 'ama@example.com']);

    $this->post('/login', ['login' => 'Ama@Example.com', 'password' => 'password'])
        ->assertRedirect('/maisons');

    $this->assertAuthenticatedAs($user);
});

test('connexion par téléphone, saisi au format local', function (): void {
    $user = User::factory()->create(['phone' => '+2290197000000']);

    $this->post('/login', ['login' => '01 97 00 00 00', 'password' => 'password'])
        ->assertRedirect('/maisons');

    $this->assertAuthenticatedAs($user);
});

test('mauvais mot de passe : refus avec message en français', function (): void {
    User::factory()->create(['email' => 'ama@example.com']);

    $this->from('/login')
        ->post('/login', ['login' => 'ama@example.com', 'password' => 'faux'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['login' => 'Ces identifiants ne correspondent à aucun compte.']);

    $this->assertGuest();
});

test('inscription : email en minuscules, téléphone normalisé, email à vérifier', function (): void {
    $this->post('/register', [
        'name' => 'Koffi Agossou',
        'email' => 'Koffi@Example.com',
        'phone' => '97 00 00 01',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
    ])->assertRedirect('/maisons');

    $user = User::query()->sole();
    expect($user->email)->toBe('koffi@example.com')
        ->and($user->phone)->toBe('+2290197000001')
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $this->get('/maisons')->assertRedirect('/email/verify');
});

test('inscription refusée pour un numéro non béninois', function (): void {
    $this->post('/register', [
        'name' => 'Koffi Agossou',
        'email' => 'koffi@example.com',
        'phone' => '+33 6 12 34 56 78',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
    ])->assertSessionHasErrors('phone');

    $this->assertGuest();
});

test('un propriétaire vérifié voit sa page « Mes maisons »', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/maisons')
        ->assertOk()
        ->assertSee('Mes maisons')
        ->assertSeeText('Aucune maison pour l\'instant');
});
