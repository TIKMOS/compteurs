<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\ChangeUtilityPricing;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Support\Journal;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

// EF-35 / ENF-10 : création, modification, saisie, estimation, clôture… sont tracées, avec
// leur auteur, dans le journal « compteurs ».

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-30 18:00:00');
    $this->owner = User::factory()->create(['name' => 'Ama Dossou']);
    $this->actingAs($this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('le cycle d\'un mois laisse une trace lisible, attribuée au propriétaire', function (): void {
    $house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $house->pricings()->update(['effective_from' => '2026-10-01']);
    $unit = Unit::factory()->for($house)->create(['label' => 'Chambre 1']);
    $meter = $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1250']);
    app(StartOccupancy::class)->execute($unit->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'A. Houngbédji']), CarbonImmutable::parse('2026-10-01'), ['electricity' => '1250']);

    $october = app(OpenBillingPeriod::class)->execute($house, CarbonImmutable::parse('2026-10-01'));
    app(RecordMonthEndReading::class)->execute($october, $meter, '1392');
    app(RecordUtilityBill::class)->execute($october, Utility::Electricity, 19367, null);
    app(CloseBillingPeriod::class)->execute($october, $this->owner);
    app(ChangeUtilityPricing::class)->execute($house, Utility::Water, PricingRule::fixed('600'), CarbonImmutable::parse('2026-11-01'), $this->owner, true);

    $log = Activity::query()->where('log_name', Journal::LOG)->orderBy('id')->get();

    expect($log->pluck('description')->all())->toBe([
        'Entrée de A. Houngbédji (Chambre 1) le 01/10/2026',
        "Relevé saisi : Chambre 1 (électricité), fin octobre 2026 = 1\u{00A0}392",
        "Facture d'électricité d'octobre 2026 : 19\u{00A0}367 FCFA",
        "Mois d'octobre 2026 clôturé",
        "Calcul d'eau : Prix fixé : 600,00 FCFA/m³, plafonné au prix réel, à partir de novembre 2026",
    ])
        ->and($log->pluck('event')->all())->toBe(['entry', 'reading_recorded', 'bill_recorded', 'closed', 'pricing_changed'])
        ->and($log->every(fn (Activity $a) => $a->causer_id === $this->owner->id))->toBeTrue();

    $closed = $log->firstWhere('event', 'closed');
    expect($closed->properties['utilities']['electricity'])->toBe([
        'unit_price' => '19367/142',
        'price_source' => 'actual',
        'participations' => 19367,
    ])->and($log->last()->properties['compliance_confirmations'])->toBe(['fixed_price_enabled']);
});

test('l\'écran de création d\'une maison est tracé', function (): void {
    Livewire\Livewire::test('app.maisons.nouveau')->set('name', 'Maison Akpakpa')->call('save');

    $entry = Activity::query()->where('log_name', Journal::LOG)->sole();
    expect($entry->description)->toBe('Maison « Maison Akpakpa » créée')
        ->and($entry->subject_type)->toBe(House::class)
        ->and($entry->causer_id)->toBe($this->owner->id);
});
