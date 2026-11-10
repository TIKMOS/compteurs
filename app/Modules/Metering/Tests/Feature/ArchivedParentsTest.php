<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\SubMeter;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\CreateTenantAccessLink;
use App\Modules\Metering\Queries\MissingMonthEndReadings;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;

// PHPStan niveau 8 (revue M-5) : les relations vers la maison et le logement renvoient le vrai parent,
// même archivé ; les écrans qui doivent ignorer les archivés le filtrent explicitement.

beforeEach(function (): void {
    $this->seed(DemoSeeder::class);
    $this->owner = User::query()->sole();
    $this->house = House::query()->sole();
});

test('une période, un logement ou un décompteur retrouvent leur parent même archivé', function (): void {
    $period = BillingPeriod::query()->where('house_id', $this->house->id)->firstOrFail();
    $meter = SubMeter::query()->firstOrFail();
    $this->house->delete();
    $meter->unit->delete();

    expect($period->fresh()->house->id)->toBe($this->house->id)
        ->and(Unit::withTrashed()->findOrFail($meter->unit_id)->house->id)->toBe($this->house->id)
        ->and($meter->fresh()->unit->id)->toBe($meter->unit_id);
});

test('portail locataire : les mois d\'une maison archivée ne sont plus affichés', function (): void {
    $tenant = Tenant::query()->whereHas('occupancies')->firstOrFail();
    $url = app(CreateTenantAccessLink::class)->execute($tenant, $this->owner);
    auth()->logout();
    $occupancyId = $tenant->occupancies()->value('id');

    $this->get($url)->assertOk()->assertSeeText('Octobre 2026');
    $this->get("{$url}/fiche/{$occupancyId}/2026-10")->assertOk();

    $this->house->delete();

    $this->get($url)->assertOk()->assertDontSeeText('Octobre 2026');
    $this->get("{$url}/fiche/{$occupancyId}/2026-10")->assertNotFound();
});

test('relevés manquants : le décompteur d\'un logement archivé ne compte pas', function (): void {
    $month = CarbonImmutable::parse('2026-11-01');
    $before = app(MissingMonthEndReadings::class)->forHouse($this->house, $month)[0]['missing'];

    $unit = Unit::factory()->for($this->house)->create(['label' => 'Annexe']);
    $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '0']);
    expect(app(MissingMonthEndReadings::class)->forHouse($this->house, $month)[0]['missing'])->toBe($before + 1);

    $unit->delete();
    expect(app(MissingMonthEndReadings::class)->forHouse($this->house, $month)[0]['missing'])->toBe($before);
});
