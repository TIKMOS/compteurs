<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\EndOccupancy;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 1, tranche 3 — UC-05, UC-06 : entrée et sortie avec relevés (EF-07, EF-08, EF-12),
// logement vacant (EF-10), chaîne des index (EF-17). Chambre 2 de CONCEPTION.md § 5.3.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-30 18:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 2']);
    $this->unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-08-01', 'install_index' => '3290']);
    $this->unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-08-01', 'install_index' => '174']);
    $this->dossou = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'B. Dossou']);
    $this->start = app(StartOccupancy::class);
    $this->end = app(EndOccupancy::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('entrée : occupation, un relevé d\'entrée par décompteur, période du mois ouverte', function (): void {
    $occupancy = $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290.000', 'water' => '174.000']);

    $period = BillingPeriod::query()->sole();
    expect($occupancy->started_on->toDateString())->toBe('2026-08-01')
        ->and($occupancy->isCurrent())->toBeTrue()
        ->and($period->month->toDateString())->toBe('2026-08-01')
        ->and($period->status)->toBe(PeriodStatus::Draft)
        ->and(MeterReading::query()->where('kind', ReadingKind::Entry)->where('occupancy_id', $occupancy->id)->count())->toBe(2)
        ->and($this->unit->fresh()->load('occupancies')->currentOccupancy()->id)->toBe($occupancy->id);
});

test('T-04 sortie, vacance puis entrée : relevés de sortie et d\'entrée, logement vacant entre les deux', function (): void {
    $dossou = $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);

    $this->end->execute($dossou, day('2026-11-15'), ['electricity' => '3626', 'water' => '199']);
    $unit = $this->unit->fresh()->load('occupancies');
    expect($unit->currentOccupancy())->toBeNull()
        ->and($unit->vacantSince()->toDateString())->toBe('2026-11-15');

    $kpadonou = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'D. Kpadonou']);
    $this->start->execute($this->unit->fresh(), $kpadonou, day('2026-11-20'), ['electricity' => '3631', 'water' => '199.5']);

    $readings = MeterReading::query()->whereHas('subMeter', fn ($q) => $q->where('utility', 'electricity'))
        ->orderBy('read_on')->get()->map(fn ($r) => [$r->kind->value, $r->read_on->toDateString(), $r->index_value])->all();

    expect($readings)->toBe([
        ['entry', '2026-08-01', '3290.000'],
        ['exit', '2026-11-15', '3626.000'],
        ['entry', '2026-11-20', '3631.000'],
    ])->and(Occupancy::query()->count())->toBe(2);
});

test('T-05 passation le même jour, au même index', function (): void {
    $dossou = $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);
    $this->end->execute($dossou, day('2026-11-15'), ['electricity' => '3626', 'water' => '199']);

    $kpadonou = Tenant::factory()->for($this->owner, 'owner')->create();
    $occupancy = $this->start->execute($this->unit->fresh(), $kpadonou, day('2026-11-15'), ['electricity' => '3626', 'water' => '199']);

    expect($occupancy->started_on->toDateString())->toBe('2026-11-15');

    // Le même jour, l'entrant ne peut pas partir d'un index inférieur à celui de la sortie.
    $this->end->execute($occupancy, day('2026-11-15'), ['electricity' => '3626', 'water' => '199']);
    expect(fn () => $this->start->execute($this->unit->fresh(), $this->dossou, day('2026-11-15'), ['electricity' => '3600', 'water' => '199']))
        ->toThrow(ReadingRejectedException::class, "L'index saisi (3\u{00A0}600) est inférieur à l'index précédent (3\u{00A0}626).");
});

test('entrée refusée : logement occupé, date future, avant la dernière sortie, index manquant', function (): void {
    $dossou = $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);
    $other = Tenant::factory()->for($this->owner, 'owner')->create();

    expect(fn () => $this->start->execute($this->unit->fresh(), $other, day('2026-11-20'), ['electricity' => '3700', 'water' => '200']))
        ->toThrow(OccupancyRejectedException::class, 'Chambre 2 est occupé par B. Dossou depuis le 01/08/2026 : enregistrez d\'abord sa sortie.');

    $this->end->execute($dossou, day('2026-11-15'), ['electricity' => '3626', 'water' => '199']);

    expect(fn () => $this->start->execute($this->unit->fresh(), $other, day('2026-12-01'), ['electricity' => '3700', 'water' => '200']))
        ->toThrow(OccupancyRejectedException::class, 'La date d\'entrée ne peut pas être dans le futur.')
        ->and(fn () => $this->start->execute($this->unit->fresh(), $other, day('2026-11-10'), ['electricity' => '3700', 'water' => '200']))
        ->toThrow(OccupancyRejectedException::class, 'La date d\'entrée ne peut pas précéder la dernière sortie (15/11/2026).')
        ->and(fn () => $this->start->execute($this->unit->fresh(), $other, day('2026-11-20'), ['electricity' => '3700']))
        ->toThrow(ReadingRejectedException::class, "Saisissez l'index d'entrée du décompteur d'eau.");
});

test('EF-17 index d\'entrée sous l\'index de pose, ou date avant la pose : refus', function (): void {
    expect(fn () => $this->start->execute($this->unit, $this->dossou, day('2026-08-02'), ['electricity' => '3280', 'water' => '174']))
        ->toThrow(ReadingRejectedException::class, "L'index saisi (3\u{00A0}280) est inférieur à l'index précédent (3\u{00A0}290).")
        ->and(fn () => $this->start->execute($this->unit, $this->dossou, day('2026-07-15'), ['electricity' => '3290', 'water' => '174']))
        ->toThrow(ReadingRejectedException::class, "Le décompteur d'électricité a été posé le 01/08/2026 : la date doit être postérieure.");

    expect(Occupancy::query()->count())->toBe(0)
        ->and(MeterReading::query()->count())->toBe(0);
});

test('sortie refusée : avant l\'entrée, index sous l\'entrée, déjà sorti', function (): void {
    $occupancy = $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);

    expect(fn () => $this->end->execute($occupancy, day('2026-07-31'), ['electricity' => '3300', 'water' => '180']))
        ->toThrow(OccupancyRejectedException::class, 'La date de sortie ne peut pas précéder la date d\'entrée (01/08/2026).')
        ->and(fn () => $this->end->execute($occupancy, day('2026-11-15'), ['electricity' => '3626', 'water' => '170']))
        ->toThrow(ReadingRejectedException::class);

    $this->end->execute($occupancy, day('2026-11-15'), ['electricity' => '3626', 'water' => '199']);

    expect(fn () => $this->end->execute($occupancy, day('2026-11-16'), ['electricity' => '3627', 'water' => '199']))
        ->toThrow(OccupancyRejectedException::class, 'Cette sortie est déjà enregistrée.');
});

test('un mois clôturé refuse les entrées et les sorties', function (): void {
    BillingPeriod::query()->forceCreate([
        'ulid' => (string) Str::ulid(), 'house_id' => $this->house->id, 'month' => '2026-08-01', 'status' => PeriodStatus::Closed,
    ]);

    expect(fn () => $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']))
        ->toThrow(PeriodClosedException::class, 'Août 2026 est clôturé : il ne peut plus être modifié.');
});

test('un logement sans décompteur d\'eau n\'exige que l\'index d\'électricité', function (): void {
    $shop = Unit::factory()->for($this->house)->create(['label' => 'Boutique']);
    $shop->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-08-01', 'install_index' => '5000']);

    $this->start->execute($shop, $this->dossou, day('2026-09-01'), ['electricity' => '5040']);

    expect(MeterReading::query()->count())->toBe(1);
});

test('H-44 la pose d\'un décompteur ne se corrige plus une fois relevé', function (): void {
    $meter = $this->unit->activeSubMeter(Utility::Electricity);
    expect($meter->installationIsEditable())->toBeTrue();

    $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);

    expect($meter->fresh()->installationIsEditable())->toBeFalse();
});

test('écran d\'entrée : nouveau locataire, saisie à la française', function (): void {
    Livewire::test('app.logements.entree', ['house' => $this->house, 'unit' => $this->unit])
        ->set('tenantChoice', 'new')
        ->set('tenantName', 'D. Kpadonou')
        ->set('tenantPhone', '97 11 22 33')
        ->set('readOn', '2026-11-20')
        ->set('indexes.electricity', '3 631')
        ->set('indexes.water', '199,5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('logements.show', [$this->house, $this->unit]));

    $tenant = Tenant::query()->where('name', 'D. Kpadonou')->sole();
    expect($tenant->phone)->toBe('+2290197112233')
        ->and($tenant->occupancies()->sole()->started_on->toDateString())->toBe('2026-11-20')
        ->and(MeterReading::query()->where('index_value', '199.500')->exists())->toBeTrue();
});

test('écran d\'entrée : un index refusé s\'affiche sous le bon champ, rien n\'est créé', function (): void {
    Livewire::test('app.logements.entree', ['house' => $this->house, 'unit' => $this->unit])
        ->set('tenantChoice', 'existing')
        ->set('tenantId', (string) $this->dossou->id)
        ->set('readOn', '2026-11-20')
        ->set('indexes.electricity', '3631')
        ->set('indexes.water', '150')
        ->call('save')
        ->assertHasErrors('indexes.water')
        ->assertHasNoErrors('indexes.electricity');

    expect(Occupancy::query()->count())->toBe(0);
});

test('écran de sortie, puis fiche : logement vacant, ancien occupant listé', function (): void {
    $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);

    Livewire::test('app.logements.sortie', ['house' => $this->house, 'unit' => $this->unit->fresh()])
        ->assertSee('Sortie de B. Dossou')
        ->set('readOn', '2026-11-15')
        ->set('indexes.electricity', '3626')
        ->set('indexes.water', '199')
        ->call('save')
        ->assertHasNoErrors();

    $this->get(route('logements.show', [$this->house, $this->unit]))
        ->assertSeeText('Vacant')
        ->assertSeeText('Depuis le 15/11/2026.')
        ->assertSeeText('B. Dossou : du 01/08/2026 au 15/11/2026');
});

test('un logement occupé ne peut pas être archivé', function (): void {
    $this->start->execute($this->unit, $this->dossou, day('2026-08-01'), ['electricity' => '3290', 'water' => '174']);

    Livewire::test('app.logements.detail', ['house' => $this->house, 'unit' => $this->unit->fresh()])
        ->call('archive')
        ->assertHasErrors('archive');

    expect($this->unit->fresh()->trashed())->toBeFalse();
});
