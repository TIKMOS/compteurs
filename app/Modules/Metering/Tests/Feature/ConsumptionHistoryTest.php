<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\EndOccupancy;
use App\Modules\Metering\Actions\EstimateMeterReading;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Queries\ConsumptionHistory;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 2 (prompt), tranche 4 — historique par logement et par locataire (12 derniers mois).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-12-10 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    $this->unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $this->elec = $this->unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1250']);
    $this->tenant = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'A. Houngbédji']);
    $this->occ = app(StartOccupancy::class)->execute($this->unit->fresh(), $this->tenant, CarbonImmutable::parse('2026-10-01'), ['electricity' => '1250']);

    // Octobre réel : 142 kWh pour 19 367 FCFA (prix réel 136,387).
    $october = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    app(RecordMonthEndReading::class)->execute($october, $this->elec, '1392');
    app(RecordUtilityBill::class)->execute($october, Utility::Electricity, 19367, null);
    app(CloseBillingPeriod::class)->execute($october, $this->owner);

    // Novembre estimé, décembre réel (régularisation).
    $november = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
    app(EstimateMeterReading::class)->execute($november, $this->elec);
    app(RecordUtilityBill::class)->execute($november, Utility::Electricity, 18000, null);
    app(CloseBillingPeriod::class)->execute($november, $this->owner);

    $december = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-12-01'));
    app(RecordMonthEndReading::class)->execute($december, $this->elec, '1700');
    app(RecordUtilityBill::class)->execute($december, Utility::Electricity, 20000, null);
    app(CloseBillingPeriod::class)->execute($december, $this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('historique d\'un logement : mois récents d\'abord, consommation, prix, montant, estimé ou réel', function (): void {
    $rows = app(ConsumptionHistory::class)->forUnit($this->unit);

    expect(array_map(fn ($r) => $r['period']->month->format('Y-m'), $rows))->toBe(['2026-12', '2026-11', '2026-10']);

    $oct = $rows[2]['utilities']['electricity'];
    expect($oct['consumption'])->toBe(142000)
        ->and($oct['amount'])->toBe(19367)
        ->and($oct['prices'])->toBe(['136,387'])
        ->and($oct['estimated'])->toBeFalse()
        ->and($rows[2]['total'])->toBe(19367);

    expect($rows[1]['utilities']['electricity']['estimated'])->toBeTrue()
        ->and($rows[0]['utilities']['electricity']['estimated'])->toBeFalse()
        ->and($rows[0]['utilities']['electricity']['regularized'])->toBeTrue();

    // Montants figés : la somme des mois = la somme des participations du logement (une seule occupation ici).
    expect(array_sum(array_column($rows, 'total')))->toBe(19367 + 18000 + 20000);
});

test('limite au nombre de mois demandé (12 par défaut)', function (): void {
    expect(app(ConsumptionHistory::class)->forUnit($this->unit, 2))->toHaveCount(2);
});

test('historique d\'un locataire : seulement ses participations', function (): void {
    // Changement de locataire le 15 janvier : chacun paie sa tranche du mois.
    CarbonImmutable::setTestNow('2027-02-02 10:00:00');
    $january = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2027-01-01'));
    app(EndOccupancy::class)->execute($this->occ->fresh(), CarbonImmutable::parse('2027-01-15'), ['electricity' => '1750']);
    $other = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'B. Dossou']);
    app(StartOccupancy::class)->execute($this->unit->fresh(), $other, CarbonImmutable::parse('2027-01-16'), ['electricity' => '1750']);
    app(RecordMonthEndReading::class)->execute($january->fresh(), $this->elec, '1800');
    app(RecordUtilityBill::class)->execute($january->fresh(), Utility::Electricity, 14000, null);
    app(CloseBillingPeriod::class)->execute($january->fresh(), $this->owner);

    $months = fn ($rows) => array_map(fn ($r) => $r['period']->month->format('Y-m'), $rows);
    $mine = app(ConsumptionHistory::class)->forTenant($this->tenant);
    $theirs = app(ConsumptionHistory::class)->forTenant($other);
    $unit = app(ConsumptionHistory::class)->forUnit($this->unit);

    expect($months($mine))->toBe(['2027-01', '2026-12', '2026-11', '2026-10'])
        ->and($months($theirs))->toBe(['2027-01'])
        ->and($mine[0]['utilities']['electricity']['consumption'])->toBe(50000)
        ->and($theirs[0]['utilities']['electricity']['consumption'])->toBe(50000)
        // Le logement additionne les deux tranches du mois.
        ->and($unit[0]['utilities']['electricity']['consumption'])->toBe(100000)
        ->and($unit[0]['total'])->toBe($mine[0]['total'] + $theirs[0]['total']);
});

test('fiche logement et fiche locataire : tableau et graphique', function (): void {
    Livewire::test('app.logements.detail', ['house' => $this->house, 'unit' => $this->unit])
        ->assertSee('Historique des 12 derniers mois')
        ->assertSee('FCFA/kWh')
        ->assertSee('142 kWh')
        ->assertSee('136,387')
        ->assertSee('Estimé')
        ->assertSee('role="img"', false)
        ->assertSee('Hachuré : mois facturé sur un relevé estimé.');

    Livewire::test('app.locataires.detail', ['tenant' => $this->tenant])
        ->assertSee('Historique des 12 derniers mois')
        ->assertSee("19\u{00A0}367 FCFA");
});

test('sans mois clôturé : message d\'attente', function (): void {
    $unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 2']);

    Livewire::test('app.logements.detail', ['house' => $this->house, 'unit' => $unit])
        ->assertSeeText("Aucun mois clôturé pour l'instant", false);
});
