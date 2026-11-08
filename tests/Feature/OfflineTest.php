<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Unit;
use App\Models\User;
use App\Shared\Enums\Utility;

// Lot 3, tranche 4 — ENF-04 : application installable, consultation hors ligne, index en attente.
// La file d'attente elle-même est testée avec Node : npm run test:js.

test('manifeste de l\'application installable', function (): void {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['short_name'])->toBe('Compteurs')
        ->and($manifest['lang'])->toBe('fr')
        ->and($manifest['start_url'])->toBe('/maisons')
        ->and($manifest['display'])->toBe('standalone')
        ->and(array_column($manifest['icons'], 'sizes'))->toBe(['192x192', '512x512']);

    foreach ($manifest['icons'] as $icon) {
        [$w, $h] = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect("{$w}x{$h}")->toBe($icon['sizes']);
    }
});

test('les pages déclarent le manifeste ; la page hors connexion est publique', function (): void {
    $this->get('/login')->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
    $this->get('/hors-ligne')->assertOk()->assertSeeText('Pas de connexion');
});

test('service worker : jamais de cache pour Livewire, les POST, les PDF, les photos ni l\'export', function (): void {
    $sw = file_get_contents(public_path('sw.js'));

    expect($sw)->toContain("request.method !== 'GET'")
        ->toContain("url.pathname.startsWith('/livewire')")
        ->toContain('\.pdf$|\.csv$|\/photo$')
        ->toContain("event.data === 'clear'")
        ->toContain("const OFFLINE_URL = '/hors-ligne'");
});

test('saisie : chaque index passe par la file d\'attente hors ligne', function (): void {
    $owner = User::factory()->create();
    $house = House::factory()->for($owner, 'owner')->create();
    $unit = Unit::factory()->for($house)->create();
    $meter = $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => now()->startOfMonth(), 'install_index' => '0']);

    $this->actingAs($owner)
        ->get(route('periodes.show', [$house, now()->format('Y-m')]))
        ->assertOk()
        ->assertSee("offlineIndex({ page: '{$house->ulid}\/" . now()->format('Y-m') . "', meterId: {$meter->id} })", false)
        ->assertSee('x-on:change="save()"', false)
        ->assertSeeText('En attente du réseau')
        ->assertSee('wire:offline', false);
});
