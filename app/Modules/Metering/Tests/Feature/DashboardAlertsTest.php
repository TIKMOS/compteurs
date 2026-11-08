<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\ChangeUtilityPricing;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\EstimateMeterReading;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordPayment;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Queries\HouseAlerts;
use App\Modules\Metering\Queries\HouseDashboard;
use App\Shared\Enums\PaymentMethod;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 2 (prompt), tranche 3 — tableau de bord : mois en cours, encaissé, impayés, alertes.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-12-10 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    $this->unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $this->elec = $this->unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1250']);
    $this->tenant = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'A. Houngbédji']);
    $this->occ = app(StartOccupancy::class)->execute($this->unit->fresh(), $this->tenant, CarbonImmutable::parse('2026-10-01'), ['electricity' => '1250']);

    // Octobre clôturé : 142 kWh, 19 367 FCFA.
    $october = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    app(RecordMonthEndReading::class)->execute($october, $this->elec, '1392');
    app(RecordUtilityBill::class)->execute($october, Utility::Electricity, 19367, null);
    $this->october = app(CloseBillingPeriod::class)->execute($october, $this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function alertTypes(): array
{
    return array_map(fn ($a) => $a['type'] . ':' . $a['tone'], app(HouseAlerts::class)->execute(test()->house));
}

test('mois en cours : pas commencé, puis brouillon avec l\'avancement', function (): void {
    $current = app(HouseDashboard::class)->currentMonth($this->house);
    expect($current['status'])->toBe('Pas commencé')
        ->and($current['month']->format('Y-m'))->toBe('2026-12')
        ->and([$current['done'], $current['needed']])->toBe([0, 1]);

    app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-12-01'));
    expect(app(HouseDashboard::class)->currentMonth($this->house)['status'])->toBe('Brouillon');
});

test('totaux : encaissé, impayés, à encaisser', function (): void {
    app(RecordPayment::class)->execute($this->occ, $this->october, 10000, CarbonImmutable::parse('2026-11-05'), PaymentMethod::MtnMomo, $this->owner);

    expect(app(HouseDashboard::class)->collected($this->house))->toBe(10000);

    Livewire::test('app.maisons.tableau-de-bord', ['house' => $this->house])
        ->assertSeeInOrder(['À encaisser', "9\u{00A0}367 FCFA", 'Encaissé', "10\u{00A0}000 FCFA", 'Impayés (1)', "9\u{00A0}367 FCFA"]);
});

test('alerte relevés : mois en cours neutre, mois passé en brouillon en retard', function (): void {
    expect(alertTypes())->toBe(['missing_readings:neutral']);

    // Novembre ouvert mais sans relevé : il passe en retard.
    app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
    $alerts = app(HouseAlerts::class)->execute($this->house);

    expect(array_column($alerts, 'tone'))->toBe(['danger', 'neutral'])
        ->and($alerts[0]['message'])->toBe('Novembre 2026 : 1 relevé de fin de mois manquant : le mois ne peut pas être clôturé.')
        ->and($alerts[0]['url'])->toBe(route('periodes.show', [$this->house, '2026-11']));
});

test('alerte estimation en cours, qui disparaît au relevé réel suivant', function (): void {
    $november = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
    app(EstimateMeterReading::class)->execute($november, $this->elec);

    expect(alertTypes())->toContain('estimate_pending:info');
    $message = collect(app(HouseAlerts::class)->execute($this->house))->firstWhere('type', 'estimate_pending')['message'];
    expect($message)->toBe('Chambre 1 (électricité) : relevé de novembre 2026 estimé, à régulariser au prochain relevé réel.');

    $december = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-12-01'));
    app(RecordMonthEndReading::class)->execute($december, $this->elec, '1700');

    expect(alertTypes())->not->toContain('estimate_pending:info');
});

test('alerte crédit de consommation restant', function (): void {
    // Novembre estimé (1 534) puis réel de décembre plus bas (1 520) : crédit de 14 kWh.
    $november = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
    app(EstimateMeterReading::class)->execute($november, $this->elec);
    app(RecordUtilityBill::class)->execute($november, Utility::Electricity, 18000, null);
    app(CloseBillingPeriod::class)->execute($november, $this->owner);

    $december = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-12-01'));
    app(RecordMonthEndReading::class)->execute($december, $this->elec, '1520');
    app(RecordUtilityBill::class)->execute($december, Utility::Electricity, 1000, null);
    // Σ consommations nulle ce mois-ci : on passe en mode 2 sans plafond pour pouvoir clôturer (EF-44).
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Electricity, PricingRule::fixed('125', false), CarbonImmutable::parse('2026-12-01'), $this->owner, true);
    app(CloseBillingPeriod::class)->execute($december, $this->owner);

    $credit = collect(app(HouseAlerts::class)->execute($this->house))->firstWhere('type', 'credit_remaining');
    expect($credit['message'])->toBe('Crédit de consommation restant : A. Houngbédji, 14 kWh (électricité).');
});

test('alerte mode 2 sans plafond, avec rappel de responsabilité', function (): void {
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Water, PricingRule::fixed('600', false), CarbonImmutable::parse('2026-12-01'), $this->owner, true);

    $alert = collect(app(HouseAlerts::class)->execute($this->house))->firstWhere('type', 'uncapped_fixed_price');
    expect($alert['tone'])->toBe('warning')
        ->and($alert['message'])->toContain('Eau : prix fixé sans plafond au prix réel')
        ->toContain('Vous restez seul responsable du respect de vos contrats SBEE et SONEB.')
        ->and($alert['url'])->toBe(route('maisons.settings', $this->house));

    Livewire::test('app.maisons.tableau-de-bord', ['house' => $this->house])
        ->assertSeeText('Alertes')
        ->assertSeeText('Mode 2')
        ->assertSeeText('Décembre 2026');
});
