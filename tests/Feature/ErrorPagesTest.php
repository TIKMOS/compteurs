<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

// Revue avant déploiement (I-1) : pages d'erreur en français, autonomes, avec un lien de retour.

test('404, 403, 419, 429, 500 et 503 s\'affichent en français', function (int $status, string $title): void {
    Route::get("/_test-erreur/{$status}", fn () => abort($status));

    $response = $this->get("/_test-erreur/{$status}");

    $response->assertStatus($status)
        ->assertSeeText($title)
        ->assertSeeText("ERREUR {$status}")
        ->assertDontSeeText('Not Found')
        ->assertDontSeeText('Page Expired')
        ->assertDontSeeText('Server Error')
        ->assertDontSeeText('Too Many Requests');
})->with([
    [404, 'Page introuvable'],
    [403, 'Accès refusé'],
    [419, 'Page expirée'],
    [429, 'Trop de tentatives'],
    [500, 'Une erreur est survenue'],
    [503, 'Maintenance en cours'],
]);

test('une adresse inconnue donne la page introuvable en français', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/maisons/inexistante')
        ->assertNotFound()
        ->assertSeeText('Page introuvable')
        ->assertSee('Retour à mes maisons');
});

test('autres codes : pages génériques 4xx et 5xx', function (): void {
    Route::get('/_test-erreur/410', fn () => abort(410));
    Route::get('/_test-erreur/502', fn () => abort(502));

    $this->get('/_test-erreur/410')->assertStatus(410)->assertSeeText('Demande impossible')->assertSeeText('ERREUR 410');
    $this->get('/_test-erreur/502')->assertStatus(502)->assertSeeText('Service indisponible');
});
