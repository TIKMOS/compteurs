<?php

declare(strict_types=1);

use App\Models\ComplianceAcknowledgement;
use App\Models\House;
use App\Models\User;
use App\Modules\Metering\Actions\ChangeUtilityPricing;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Exceptions\ComplianceAcknowledgementRequiredException;
use App\Shared\Enums\AcknowledgementKind;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// UC-22 / EF-46 : historique des prix avec mois d'effet (D-18, D-24), confirmations (EF-48, T-51, T-52).
// La règle « mois d'effet après la dernière période clôturée » (T-47) sera testée avec les
// périodes, en tranche 4 : aucune période n'existe encore.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->change = app(ChangeUtilityPricing::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function month(string $ym): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m', $ym);
}

test('T-51 passage au mode 2 : refusé sans confirmation, puis enregistré avec une confirmation', function (): void {
    expect(fn () => $this->change->execute($this->house, Utility::Electricity, PricingRule::fixed('127.50'), month('2026-11'), $this->owner))
        ->toThrow(ComplianceAcknowledgementRequiredException::class);

    expect($this->house->fresh()->currentPricing(Utility::Electricity)->mode)->toBe(PricingMode::BillSplit);

    $version = $this->change->execute($this->house, Utility::Electricity, PricingRule::fixed('127.50'), month('2026-11'), $this->owner, true, '10.0.0.1');

    expect($version->mode)->toBe(PricingMode::FixedPrice)
        ->and($version->fixed_unit_price)->toBe('127.50')
        ->and(ComplianceAcknowledgement::query()->sole()->kind)->toBe(AcknowledgementKind::FixedPriceEnabled);
});

test('T-52 désactiver le plafond exige une confirmation ; le réactiver ou changer le prix, non', function (): void {
    $this->change->execute($this->house, Utility::Water, PricingRule::fixed('600'), month('2026-11'), $this->owner, true);

    expect(fn () => $this->change->execute($this->house, Utility::Water, PricingRule::fixed('600', false), month('2026-12'), $this->owner))
        ->toThrow(ComplianceAcknowledgementRequiredException::class);

    $this->change->execute($this->house, Utility::Water, PricingRule::fixed('600', false), month('2026-12'), $this->owner, true);
    $this->change->execute($this->house, Utility::Water, PricingRule::fixed('600', true), month('2027-01'), $this->owner);
    $this->change->execute($this->house, Utility::Water, PricingRule::fixed('620'), month('2027-02'), $this->owner);

    expect(ComplianceAcknowledgement::query()->orderBy('id')->pluck('kind')->all())
        ->toBe([AcknowledgementKind::FixedPriceEnabled, AcknowledgementKind::CapDisabled]);
});

test('retour au mode 1 : aucune confirmation', function (): void {
    $this->change->execute($this->house, Utility::Water, PricingRule::fixed('600'), month('2026-11'), $this->owner, true);
    $this->change->execute($this->house, Utility::Water, PricingRule::billSplit(), month('2026-12'), $this->owner);

    expect(ComplianceAcknowledgement::query()->count())->toBe(1);
});

test('T-46 historique : chaque mois utilise la version la plus récente dont le mois d\'effet est passé', function (): void {
    $this->change->execute($this->house, Utility::Electricity, PricingRule::fixed('127.50'), month('2026-12'), $this->owner, true);
    $this->change->execute($this->house, Utility::Electricity, PricingRule::fixed('135'), month('2027-03'), $this->owner);

    $house = $this->house->fresh();
    $at = fn (string $ym) => $house->pricingFor(Utility::Electricity, month($ym))->rule();

    expect($at('2026-11')->mode)->toBe(PricingMode::BillSplit)
        ->and($at('2026-12')->fixedUnitPrice)->toBe('127.50')
        ->and($at('2027-02')->fixedUnitPrice)->toBe('127.50')
        ->and($at('2027-03')->fixedUnitPrice)->toBe('135.00')
        ->and($at('2027-08')->fixedUnitPrice)->toBe('135.00')
        // D-24 / ex H-33 : un mois antérieur à la première version utilise celle-ci.
        ->and($at('2026-06')->mode)->toBe(PricingMode::BillSplit)
        ->and($house->pricings)->toHaveCount(4);
});

test('T-48 une version du même mois, non utilisée par une clôture, est corrigée sur place', function (): void {
    $this->change->execute($this->house, Utility::Electricity, PricingRule::fixed('135'), month('2027-03'), $this->owner, true);
    $this->change->execute($this->house, Utility::Electricity, PricingRule::fixed('132'), month('2027-03'), $this->owner);

    $versions = $this->house->fresh()->pricings->where('utility', Utility::Electricity);
    expect($versions)->toHaveCount(2)
        ->and($versions->last()->fixed_unit_price)->toBe('132.00')
        ->and(ComplianceAcknowledgement::query()->count())->toBe(1);
});

test('aucun changement : aucune version créée', function (): void {
    expect($this->change->execute($this->house, Utility::Water, PricingRule::billSplit(), month('2026-12'), $this->owner))->toBeNull()
        ->and($this->house->fresh()->pricings)->toHaveCount(2);
});

test('écran des paramètres : passage au mode 2 avec mois d\'effet, confirmation, historique', function (): void {
    Livewire::test('app.maisons.parametres', ['house' => $this->house])
        ->assertSet('effectiveMonths.electricity', '2026-11')
        ->set('modes.electricity', 'fixed_price')
        ->set('prices.electricity', '127,50')
        ->set('effectiveMonths.electricity', '2026-12')
        ->call('saveUtility', 'electricity')
        ->assertSet('confirmingCompliance', true)
        ->assertSee('Les règlements de service de la SBEE (art. 90)')
        ->call('confirmCompliance')
        ->assertSet('confirmingCompliance', false)
        ->assertSee('nouveau calcul enregistré à partir de décembre 2026')
        ->assertSee('Prix fixé : 127,50 FCFA/kWh, plafonné au prix réel')
        ->assertSee('Activation du prix fixé — électricité');

    expect($this->house->fresh()->pricingFor(Utility::Electricity, month('2026-12'))->fixed_unit_price)->toBe('127.50');
});

test('écran des paramètres : un mois d\'effet hors de la liste est refusé', function (): void {
    Livewire::test('app.maisons.parametres', ['house' => $this->house])
        ->set('effectiveMonths.water', '2026-10')
        ->set('modes.water', 'fixed_price')
        ->set('prices.water', '600')
        ->call('saveUtility', 'water')
        ->assertHasErrors('effectiveMonths.water');
});

test('règle de tarification : prix normalisé, mode 1 neutre, facture exigée selon le mode', function (): void {
    expect(PricingRule::fixed('127,5')->fixedUnitPrice)->toBe('127.50')
        ->and(PricingRule::fixed('1 250')->fixedUnitPrice)->toBe('1250.00')
        ->and(PricingRule::billSplit()->requiresBill())->toBeTrue()
        ->and(PricingRule::fixed('150')->requiresBill())->toBeTrue()
        ->and(PricingRule::fixed('150', false)->requiresBill())->toBeFalse()
        ->and(fn () => PricingRule::fixed('0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PricingRule::fixed('12,345'))->toThrow(InvalidArgumentException::class);
});
