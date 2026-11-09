<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Revue avant déploiement (docs/REVUE.md, I-3 et M-4) : cas limites du calcul d'un mois.
// Maison de deux chambres équipées en électricité seulement (aucun décompteur d'eau).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->house->pricings()->update(['effective_from' => '2026-09-01']);
    $this->meters = [];
    foreach (['Chambre 1' => 1000, 'Chambre 2' => 2000] as $label => $index) {
        $unit = Unit::factory()->for($this->house)->create(['label' => $label]);
        $this->meters[$label] = $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-09-01', 'install_index' => $index]);
        app(StartOccupancy::class)->execute($unit->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(), CarbonImmutable::parse('2026-09-01'), ['electricity' => (string) $index]);
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

function edgeMonth(string $ym, string $index1, string $index2, ?int $bill): App\Models\BillingPeriod
{
    $period = app(OpenBillingPeriod::class)->execute(test()->house, CarbonImmutable::parse($ym . '-01'));
    app(RecordMonthEndReading::class)->execute($period, test()->meters['Chambre 1'], $index1);
    app(RecordMonthEndReading::class)->execute($period, test()->meters['Chambre 2'], $index2);
    app(RecordUtilityBill::class)->execute($period, Utility::Electricity, $bill, null);

    return $period;
}

test('H-53 aucun décompteur d\'eau dans la maison : l\'eau n\'est ni répartie ni exigée', function (): void {
    $computation = app(ComputeBillingPeriod::class)->execute(edgeMonth('2026-09', '1100', '2300', 52000));

    expect($computation->problems)->toBe([])
        ->and(array_keys($computation->allocations))->toBe(['electricity'])
        ->and($computation->allocations['electricity']->result->sum())->toBe(52000);
});

test('T-13 au niveau du mois : facture saisie mais aucune consommation, le problème est listé', function (): void {
    $computation = app(ComputeBillingPeriod::class)->execute(edgeMonth('2026-09', '1000', '2000', 5000));

    expect($computation->canClose())->toBeFalse()
        ->and($computation->problems)->toHaveCount(1)
        ->and($computation->problems[0])->toContain('Aucune consommation')->toContain("5\u{00A0}000 FCFA");
});

test('I-3 valeurs démesurées (zéros en trop) : message clair au lieu d\'une erreur 500', function (): void {
    // Mode 2 non plafonné à 9 999,99 FCFA/kWh et index à 10 chiffres : le produit dépasse 2^63.
    $this->house->pricings()->where('utility', 'electricity')->update(['mode' => 'fixed_price', 'fixed_unit_price' => '9999.99', 'cap_to_actual' => false]);
    $period = edgeMonth('2026-09', '9999999999', '9999999999', 99999999);

    $computation = app(ComputeBillingPeriod::class)->execute($period);
    expect($computation->canClose())->toBeFalse()
        ->and($computation->problems)->toBe(['Électricité : Valeurs trop grandes pour être calculées : vérifiez les index saisis et le montant de la facture (un zéro de trop ?).']);

    Livewire::test('app.periodes.recapitulatif', ['house' => $this->house, 'mois' => '2026-09'])
        ->assertOk()
        ->assertSeeText('Valeurs trop grandes pour être calculées');
});

test('I-3 valeurs hautes mais réalistes : calcul exact en mode 1', function (): void {
    // 20 millions de FCFA pour 150 000 kWh, répartis 1/3 – 2/3.
    $period = edgeMonth('2026-09', '51000', '102000', 20_000_000);
    $amounts = array_map(fn ($l) => $l->amount, array_values(app(ComputeBillingPeriod::class)->execute($period)->allocations['electricity']->result->lines));

    expect($amounts)->toEqualCanonicalizing([6_666_667, 13_333_333]);
});

test('I-3 valeurs hautes mais réalistes : calcul exact en mode 2 plafonné', function (): void {
    $this->house->pricings()->where('utility', 'electricity')->update(['mode' => 'fixed_price', 'fixed_unit_price' => '127.37', 'cap_to_actual' => true]);
    $period = edgeMonth('2026-09', '51000', '102000', 20_000_000);
    $amounts = array_map(fn ($l) => $l->amount, array_values(app(ComputeBillingPeriod::class)->execute($period)->allocations['electricity']->result->lines));

    // 50 000 et 100 000 kWh à 127,37 : 6 368 500 et 12 737 000, sous le plafond de 20 millions.
    expect($amounts)->toEqualCanonicalizing([6_368_500, 12_737_000]);
});

test('EF-17 correction d\'un mois en brouillon au-dessus du relevé réel du mois suivant : refus', function (): void {
    $september = edgeMonth('2026-09', '1100', '2300', 52000);
    edgeMonth('2026-10', '1250', '2600', 60000);

    expect(fn () => app(RecordMonthEndReading::class)->execute($september, $this->meters['Chambre 1'], '1300'))
        ->toThrow(ReadingRejectedException::class, "dépasse celui du relevé suivant (1\u{00A0}250, le 31/10/2026)");
});

test('I-7 remplacement : numéro de série de plus de 60 caractères refusé sous le champ, rien n\'est créé', function (): void {
    Livewire::test('app.periodes.saisie', ['house' => $this->house, 'mois' => '2026-11'])
        ->call('startReplacement', $this->meters['Chambre 1']->id)
        ->set('replaceDate', '2026-11-15')
        ->set('removalIndex', '1050')
        ->set('newSerial', str_repeat('A', 61))
        ->call('replace')
        ->assertHasErrors(['newSerial' => 'max'])
        ->assertSeeText('Le texte de numéro de série ne peut pas contenir plus de 60 caractères.');

    expect($this->meters['Chambre 1']->unit->subMeters()->count())->toBe(1);
});
