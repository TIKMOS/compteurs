<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\SubMeter;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\InstallSubMeter;
use App\Modules\Metering\Data\SubMeterInstallation;
use App\Modules\Metering\Exceptions\ActiveSubMeterExistsException;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

// Lot 1, tranche 2 — UC-03 : logements et décompteurs (EF-03 à EF-05), isolation (ENF-09).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('ajouter un logement avec ses deux décompteurs', function (): void {
    Livewire::test('app.logements.nouveau', ['house' => $this->house])
        ->set('label', 'Chambre 1')
        ->set('installIndexes.electricity', '1 250')
        ->set('installedOn.electricity', '2026-08-01')
        ->set('serials.electricity', 'EL-778')
        ->set('installIndexes.water', '412,5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('maisons.show', $this->house));

    $unit = Unit::query()->sole();
    $electricity = $unit->activeSubMeter(Utility::Electricity);
    $water = $unit->activeSubMeter(Utility::Water);

    expect($unit->house_id)->toBe($this->house->id)
        ->and($electricity->install_index)->toBe('1250.000')
        ->and($electricity->installed_on->toDateString())->toBe('2026-08-01')
        ->and($electricity->serial_number)->toBe('EL-778')
        ->and($water->install_index)->toBe('412.500')
        ->and($water->installed_on->toDateString())->toBe('2026-11-20');
});

test('EF-05 un logement peut n\'avoir aucun décompteur pour un fluide', function (): void {
    Livewire::test('app.logements.nouveau', ['house' => $this->house])
        ->set('label', 'Boutique')
        ->set('installIndexes.electricity', '5000')
        ->set('hasMeter.water', false)
        ->call('save')
        ->assertHasNoErrors();

    $unit = Unit::query()->sole();
    expect($unit->activeSubMeter(Utility::Electricity))->not->toBeNull()
        ->and($unit->activeSubMeter(Utility::Water))->toBeNull();

    $this->get(route('maisons.show', $this->house))->assertSeeText('Pas de décompteur d\'eau');
});

test('saisies refusées : nom en double, index illisible, date future', function (): void {
    Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);

    Livewire::test('app.logements.nouveau', ['house' => $this->house])
        ->set('label', 'Chambre 1')
        ->call('save')
        ->assertHasErrors(['label' => 'unique']);

    Livewire::test('app.logements.nouveau', ['house' => $this->house])
        ->set('label', 'Chambre 2')
        ->set('installIndexes.electricity', '12,3456')
        ->call('save')
        ->assertHasErrors('installIndexes.electricity');

    Livewire::test('app.logements.nouveau', ['house' => $this->house])
        ->set('label', 'Chambre 2')
        ->set('installIndexes.electricity', '100')
        ->set('installedOn.electricity', '2026-12-01')
        ->call('save')
        ->assertHasErrors('installedOn.electricity');

    expect(Unit::query()->count())->toBe(1);
});

test('le même nom de logement est permis dans deux maisons différentes', function (): void {
    $other = House::factory()->for($this->owner, 'owner')->create();
    Unit::factory()->for($other)->create(['label' => 'Chambre 1']);

    Livewire::test('app.logements.nouveau', ['house' => $this->house])
        ->set('label', 'Chambre 1')
        ->set('hasMeter.electricity', false)
        ->set('hasMeter.water', false)
        ->call('save')
        ->assertHasNoErrors();
});

test('H-5 les logements sont listés dans l\'ordre naturel des libellés', function (): void {
    foreach (['Chambre 10', 'Boutique', 'Chambre 2', 'chambre 1'] as $label) {
        Unit::factory()->for($this->house)->create(['label' => $label]);
    }

    $this->get(route('maisons.show', $this->house))
        ->assertSeeInOrder(['Boutique', 'chambre 1', 'Chambre 2', 'Chambre 10']);
});

test('EF-04 un seul décompteur actif par logement et par fluide', function (): void {
    $unit = Unit::factory()->for($this->house)->withSubMeters()->create();
    $installation = new SubMeterInstallation(CarbonImmutable::parse('2026-11-01'), '10.000');

    expect(fn () => app(InstallSubMeter::class)->execute($unit, Utility::Water, $installation))
        ->toThrow(ActiveSubMeterExistsException::class);

    // Garantie en base aussi : clé unique (unit_id, utility, is_active).
    expect(fn () => $unit->subMeters()->create([
        'utility' => Utility::Water, 'installed_on' => '2026-11-01', 'install_index' => '10',
    ]))->toThrow(QueryException::class);
});

test('un décompteur déposé ne bloque pas la pose d\'un nouveau', function (): void {
    $unit = Unit::factory()->for($this->house)->withSubMeters()->create();
    $unit->subMeters()->where('utility', 'water')->update(['removed_on' => '2026-11-10', 'removal_index' => '50']);

    $meter = app(InstallSubMeter::class)->execute(
        $unit, Utility::Water, new SubMeterInstallation(CarbonImmutable::parse('2026-11-10'), '0.000'),
    );

    expect($meter->isActive())->toBeTrue()
        ->and(SubMeter::query()->where('unit_id', $unit->id)->where('utility', 'water')->count())->toBe(2);
});

test('fiche du logement : renommer, ajouter le décompteur manquant, corriger la pose', function (): void {
    $unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-08-01', 'install_index' => '1250']);

    $component = Livewire::test('app.logements.detail', ['house' => $this->house, 'unit' => $unit])
        ->assertSee('Pas de décompteur : ce logement ne participe pas aux frais d', false)
        ->set('label', 'Chambre 1 bis')
        ->call('saveLabel')
        ->assertHasNoErrors()
        ->call('editMeter', 'water')
        ->set('installIndexes.water', '412')
        ->call('saveMeter')
        ->assertHasNoErrors()
        ->assertSee('Le décompteur d', false)
        ->call('editMeter', 'electricity')
        // Espace fine insécable entre les milliers (ENF-06) ; elle est acceptée à la relecture.
        ->assertSet('installIndexes.electricity', "1\u{202F}250")
        ->set('installIndexes.electricity', '1 251,5')
        ->set('serials.electricity', 'EL-9')
        ->call('saveMeter')
        ->assertHasNoErrors();

    $unit->refresh()->load('subMeters');
    expect($unit->label)->toBe('Chambre 1 bis')
        ->and($unit->activeSubMeter(Utility::Water)->install_index)->toBe('412.000')
        ->and($unit->activeSubMeter(Utility::Electricity)->install_index)->toBe('1251.500')
        ->and($unit->activeSubMeter(Utility::Electricity)->serial_number)->toBe('EL-9')
        ->and(SubMeter::query()->count())->toBe(2);
});

test('archiver un logement : il quitte la maison, ses décompteurs restent', function (): void {
    $unit = Unit::factory()->for($this->house)->withSubMeters()->create(['label' => 'Chambre 9']);

    Livewire::test('app.logements.detail', ['house' => $this->house, 'unit' => $unit])
        ->call('archive')
        ->assertRedirect(route('maisons.show', $this->house));

    expect(Unit::withTrashed()->find($unit->id)->trashed())->toBeTrue()
        ->and(SubMeter::query()->where('unit_id', $unit->id)->count())->toBe(2);

    $this->get(route('maisons.show', $this->house))->assertDontSee(route('logements.show', [$this->house, $unit]));
});

test('ENF-09 un propriétaire n\'accède pas aux logements d\'un autre', function (): void {
    $foreignHouse = House::factory()->create();
    $foreignUnit = Unit::factory()->for($foreignHouse)->create();

    $this->get(route('logements.create', $foreignHouse))->assertNotFound();
    $this->get(route('logements.show', [$foreignHouse, $foreignUnit]))->assertNotFound();
    Livewire::test('app.logements.detail', ['house' => $foreignHouse, 'unit' => $foreignUnit])->assertNotFound();
});

test('un logement n\'est trouvé que dans sa propre maison', function (): void {
    $secondHouse = House::factory()->for($this->owner, 'owner')->create();
    $unit = Unit::factory()->for($secondHouse)->create();

    $this->get("/maisons/{$this->house->ulid}/logements/{$unit->ulid}")->assertNotFound();
    $this->get("/maisons/{$secondHouse->ulid}/logements/{$unit->ulid}")->assertOk();
});
