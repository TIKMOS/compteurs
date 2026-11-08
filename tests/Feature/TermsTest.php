<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

// EF-51 / H-36 / T-55 : conditions d'utilisation acceptées à l'inscription, et à nouveau à chaque
// nouvelle version.

test('la page des conditions est publique et rappelle la responsabilité du propriétaire', function (): void {
    $this->get('/conditions-utilisation')
        ->assertOk()
        ->assertSeeText('Le propriétaire reste seul responsable du respect de ses contrats SBEE et SONEB')
        ->assertSeeText('répartition des charges');
});

test('T-55 inscription refusée sans la case, acceptée avec : version et date enregistrées', function (): void {
    $form = [
        'name' => 'Koffi Agossou',
        'email' => 'koffi@example.com',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
    ];

    $this->get('/register')->assertSeeText('J\'accepte les conditions d\'utilisation');

    $this->post('/register', $form)
        ->assertSessionHasErrors(['terms' => 'Pour créer votre compte, acceptez les conditions d\'utilisation.']);
    $this->assertGuest();

    $this->post('/register', [...$form, 'terms' => '1'])->assertRedirect('/maisons');

    $user = User::query()->sole();
    expect($user->terms_version)->toBe(config('legal.terms_version'))
        ->and($user->terms_accepted_at)->not->toBeNull();
});

test('H-36 nouvelle version : accès bloqué jusqu\'à l\'acceptation', function (): void {
    $user = User::factory()->withoutTermsAccepted()->create();
    $this->actingAs($user);

    $this->get('/maisons')->assertRedirect(route('legal.accept'));
    $this->get('/locataires')->assertRedirect(route('legal.accept'));

    // Après acceptation, retour à la dernière page demandée.
    Livewire::test('app.legal.accepter')->call('accept')->assertHasErrors('accepted');
    Livewire::test('app.legal.accepter')->set('accepted', true)->call('accept')->assertRedirect(route('locataires.index'));

    expect($user->fresh()->terms_version)->toBe(config('legal.terms_version'));
    $this->get('/maisons')->assertOk();
});

test('un compte à jour n\'est pas renvoyé vers l\'acceptation', function (): void {
    $this->actingAs(User::factory()->create())->get('/maisons')->assertOk();
    Livewire::test('app.legal.accepter')->assertRedirect(route('maisons.index'));
});
