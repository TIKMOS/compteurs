<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Http\Controllers\HistoryCsvController;
use App\Modules\Metering\Queries\HouseDashboard;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 2, tranche 4 — tableau de bord (écart de comptage, part du propriétaire), export CSV,
// journal d'activité consultable (H-58).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);

    // Chambre 1 occupée ; Chambre 2 vacante (part du propriétaire).
    $c1 = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $c2 = Unit::factory()->for($this->house)->create(['label' => 'Chambre 2']);
    foreach ([$c1, $c2] as $unit) {
        $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1000']);
    }
    app(StartOccupancy::class)->execute($c1->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'A. Houngbédji']), CarbonImmutable::parse('2026-10-01'), ['electricity' => '1000']);

    $october = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    app(RecordMonthEndReading::class)->execute($october, $c1->fresh('subMeters')->activeSubMeter(Utility::Electricity), '1150');
    app(RecordMonthEndReading::class)->execute($october, $c2->fresh('subMeters')->activeSubMeter(Utility::Electricity), '1050');
    // 200 kWh relevés aux décompteurs, 250 au compteur principal : écart de 50 kWh (20 %).
    app(RecordUtilityBill::class)->execute($october, Utility::Electricity, 30000, '250');
    $this->october = app(CloseBillingPeriod::class)->execute($october, $this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('tableau de bord : facture, participations, part du propriétaire, écart de comptage', function (): void {
    $row = app(HouseDashboard::class)->months($this->house)[0];
    $elec = $row['utilities']['electricity'];

    // 150 kWh locataire / 50 kWh vacant sur 30 000 FCFA → 22 500 / 7 500.
    expect($elec['bill'])->toBe(30000)
        ->and($elec['tenants'])->toBe(22500)
        ->and($elec['owner'])->toBe(7500)
        ->and($elec['meters'])->toBe(200_000)
        ->and($elec['gap'])->toBe(50_000)
        ->and($elec['gapPct'])->toBe('20,0');

    Livewire::test('app.maisons.tableau-de-bord', ['house' => $this->house])
        ->assertSeeText('Octobre 2026')
        ->assertSeeInOrder(['Participations des locataires', "22\u{00A0}500 FCFA", 'Part du propriétaire', "7\u{00A0}500 FCFA"])
        ->assertSeeText('50 kWh (20,0 %)')
        ->assertSeeInOrder(['À encaisser', "22\u{00A0}500 FCFA"]);
});

test('export CSV : en-têtes, une ligne par participation, format Excel français', function (): void {
    $response = $this->get(route('maisons.export', $this->house));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('historique-cotonou-fidjrosse.csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", $csv)));

    expect(substr($csv, 0, 3))->toBe("\u{FEFF}")
        ->and(str_getcsv(substr($lines[0], 3), ';', '"', ''))->toBe(HistoryCsvController::HEADERS)
        ->and(count($lines))->toBe(3)
        ->and(str_getcsv($lines[1], ';', '"', ''))->toBe(['2026-10', 'Chambre 1', 'A. Houngbédji', 'Électricité', 'kWh', '150', '0', '150', '150', 'prix réel', '22500', 'non', 'non'])
        ->and(str_getcsv($lines[2], ';', '"', ''))->toBe(['2026-10', 'Chambre 2', 'Propriétaire / vacant', 'Électricité', 'kWh', '50', '0', '50', '150', 'prix réel', '7500', 'non', 'non']);
});

test('journal : le propriétaire voit ses propres actions, pas celles des autres', function (): void {
    $stranger = User::factory()->create();
    $this->actingAs($stranger);
    app(App\Modules\Metering\Actions\SaveTenant::class)->execute($stranger, 'Locataire étranger', null);

    $this->actingAs($this->owner);
    Livewire::test('app.journal.index')
        ->assertSeeText('Mois d\'octobre 2026 clôturé')
        ->assertSeeText('Facture d\'électricité d\'octobre 2026')
        ->assertDontSeeText('Locataire étranger');

    $this->get('/journal')->assertOk();
});

test('isolation : tableau de bord et export d\'une autre maison introuvables', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('maisons.dashboard', $this->house))->assertNotFound();
    $this->get(route('maisons.export', $this->house))->assertNotFound();
});
