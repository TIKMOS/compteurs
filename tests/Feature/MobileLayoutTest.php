<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\User;
use Database\Seeders\DemoSeeder;

// Revue M-9 à M-11 : ergonomie sur téléphone (360 px). Données de démonstration : 11 impayés.

beforeEach(function (): void {
    $this->seed(DemoSeeder::class);
    $this->actingAs(User::query()->sole());
    $this->house = House::query()->sole();
});

test('M-11 paiements : 5 impayés affichés, les autres repliés derrière un lien', function (): void {
    $response = $this->get("/maisons/{$this->house->getRouteKey()}/paiements")->assertOk();
    $html = $response->getContent();

    $response->assertSeeText('Impayés (11)')->assertSeeText('Voir les 6 autres impayés');
    $before = substr($html, 0, (int) strpos($html, '<details'));
    expect(substr_count($before, 'wire:key="unpaid-'))->toBe(5)
        ->and(substr_count($html, 'wire:key="unpaid-'))->toBe(11);
});

test('M-10 saisie : flèches de mois et lien de remplacement assez grands pour le doigt (44 px)', function (): void {
    $html = $this->get("/maisons/{$this->house->getRouteKey()}/periodes/2026-10")->assertOk()->getContent();

    expect($html)->toMatch('/class="[^"]*h-11 w-11[^"]*" aria-label="Mois précédent"/')
        ->toMatch('/class="[^"]*h-11 w-11[^"]*" aria-label="Mois suivant"/');

    $november = $this->get("/maisons/{$this->house->getRouteKey()}/periodes/2026-11")->assertOk()->getContent();
    expect($november)->toMatch('/startReplacement\(\d+\)" class="[^"]*min-h-11[^"]*">\s*Décompteur changé/');
});

test('M-9 tableau de bord : les deux mois de l\'export l\'un sous l\'autre sur petit écran', function (): void {
    $this->get("/maisons/{$this->house->getRouteKey()}/tableau-de-bord")
        ->assertOk()
        ->assertSee('grid grid-cols-1 gap-3 min-[400px]:grid-cols-2', false);
});
