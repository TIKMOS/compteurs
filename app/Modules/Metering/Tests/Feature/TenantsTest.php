<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 1, tranche 3 — UC-04 / EF-06 : locataires, téléphone béninois, isolation (ENF-09).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-30 18:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('créer un locataire : téléphone normalisé, facultatif', function (): void {
    Livewire::test('app.locataires.nouveau')
        ->set('tenantName', 'A. Houngbédji')
        ->set('tenantPhone', '+229 01 97 00 00 01')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('app.locataires.nouveau')
        ->set('tenantName', 'C. Agossou')
        ->call('save')
        ->assertHasNoErrors();

    expect(Tenant::query()->orderBy('name')->pluck('phone', 'name')->all())
        ->toBe(['A. Houngbédji' => '+2290197000001', 'C. Agossou' => null]);

    $this->get(route('locataires.index'))->assertSeeText('A. Houngbédji')->assertSeeText('01 97 00 00 01');
});

test('téléphone invalide ou déjà utilisé par un de mes locataires : refus', function (): void {
    Tenant::factory()->for($this->owner, 'owner')->create(['phone' => '+2290197000001']);
    Tenant::factory()->create(['phone' => '+2290197000002']);

    Livewire::test('app.locataires.nouveau')->set('tenantName', 'X')->set('tenantPhone', '+33 6 12 34 56 78')
        ->call('save')->assertHasErrors('tenantPhone');

    Livewire::test('app.locataires.nouveau')->set('tenantName', 'X')->set('tenantPhone', '0197000001')
        ->call('save')->assertHasErrors('tenantPhone');

    // Le numéro d'un locataire d'un autre propriétaire reste libre.
    Livewire::test('app.locataires.nouveau')->set('tenantName', 'X')->set('tenantPhone', '0197000002')
        ->call('save')->assertHasNoErrors();
});

test('modifier un locataire en gardant son propre numéro', function (): void {
    $tenant = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'B. Dosou', 'phone' => '+2290197000001']);

    Livewire::test('app.locataires.detail', ['tenant' => $tenant])
        ->assertSet('tenantPhone', '01 97 00 00 01')
        ->set('editing', true)
        ->set('tenantName', 'B. Dossou')
        ->call('save')
        ->assertHasNoErrors();

    expect($tenant->fresh()->name)->toBe('B. Dossou');
});

test('archiver : refusé tant que le locataire occupe un logement', function (): void {
    $house = House::factory()->for($this->owner, 'owner')->create();
    $unit = Unit::factory()->for($house)->create();
    $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-08-01', 'install_index' => '0']);
    $tenant = Tenant::factory()->for($this->owner, 'owner')->create();
    app(StartOccupancy::class)->execute($unit, $tenant, CarbonImmutable::parse('2026-09-01'), ['electricity' => '10']);

    Livewire::test('app.locataires.detail', ['tenant' => $tenant])
        ->call('archive')
        ->assertHasErrors('archive');
    expect($tenant->fresh()->trashed())->toBeFalse();

    $free = Tenant::factory()->for($this->owner, 'owner')->create();
    Livewire::test('app.locataires.detail', ['tenant' => $free])->call('archive')->assertRedirect(route('locataires.index'));
    expect($free->fresh()->trashed())->toBeTrue();
});

test('ENF-09 : les locataires d\'un autre propriétaire sont introuvables et inutilisables', function (): void {
    $foreign = Tenant::factory()->create(['name' => 'Locataire du voisin']);

    $this->get(route('locataires.index'))->assertDontSeeText('Locataire du voisin');
    $this->get(route('locataires.show', $foreign))->assertNotFound();

    $house = House::factory()->for($this->owner, 'owner')->create();
    $unit = Unit::factory()->for($house)->create();

    expect(fn () => app(StartOccupancy::class)->execute($unit, $foreign, CarbonImmutable::parse('2026-11-01'), []))
        ->toThrow(OccupancyRejectedException::class, 'Ce locataire n\'appartient pas à vos locataires.');

    Livewire::test('app.logements.entree', ['house' => $house, 'unit' => $unit])
        ->set('tenantChoice', 'existing')
        ->set('tenantId', (string) $foreign->id)
        ->call('save')
        ->assertHasErrors('tenantId');
});
