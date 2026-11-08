<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\MeterReading;
use App\Models\SubMeter;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityBill;
use App\Modules\Metering\Actions\ChangeUtilityPricing;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Contracts\BilledConsumptionHistory;
use App\Modules\Metering\Data\PricingRule;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 1, tranche 4 — UC-07, UC-08, UC-10 à UC-12 : saisie du mois (EF-13 à EF-25), relevés de fin
// de mois, estimation, remplacement de décompteur, factures. Chambre 1 de CONCEPTION.md § 5.

/**
 * Historique facturé de substitution (la clôture n'existe qu'en tranche 5).
 */
final class FakeHistory implements BilledConsumptionHistory
{
    /** @var list<int> */
    public array $values = [];

    public function forUnit(Unit $unit, Utility $utility, CarbonImmutable $beforeMonth): array
    {
        return $this->values;
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-12-31 18:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $this->elec = $this->unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1250']);
    $this->water = $this->unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-10-01', 'install_index' => '412']);
    $this->history = new FakeHistory;
    $this->app->instance(BilledConsumptionHistory::class, $this->history);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sheet(string $mois)
{
    return Livewire::test('app.periodes.saisie', ['house' => test()->house, 'mois' => $mois]);
}

function monthEnd(SubMeter $meter, string $month): ?MeterReading
{
    return $meter->readings()->where('kind', ReadingKind::MonthEnd)
        ->whereHas('billingPeriod', fn ($q) => $q->where('month', $month . '-01'))->first();
}

test('saisie d\'un index : relevé daté du dernier jour du mois, mois ouvert, enregistré au fil de l\'eau', function (): void {
    expect(BillingPeriod::query()->count())->toBe(0);

    sheet('2026-10')
        ->assertSee('Début : 1 250 le 01/10 (pose)', false)
        ->set('indexes.' . $this->elec->id, '1 392')
        ->assertHasNoErrors()
        ->assertSet('saved.' . $this->elec->id, 'Enregistré')
        ->assertSeeText('Consommation du mois : 142 kWh');

    $reading = monthEnd($this->elec, '2026-10');
    expect($reading->read_on->toDateString())->toBe('2026-10-31')
        ->and($reading->index_value)->toBe('1392.000')
        ->and($reading->is_estimated)->toBeFalse()
        ->and(BillingPeriod::query()->sole()->status)->toBe(PeriodStatus::Draft);

    // Ressaisir corrige ; vider efface.
    sheet('2026-10')->assertSet('indexes.' . $this->elec->id, "1\u{00A0}392")
        ->set('indexes.' . $this->elec->id, '1393')->assertHasNoErrors()
        ->set('indexes.' . $this->elec->id, '')->assertSet('saved.' . $this->elec->id, 'Saisie effacée');

    expect(monthEnd($this->elec, '2026-10'))->toBeNull();
});

test('EF-14 le mois suivant part de l\'index de fin du mois précédent', function (): void {
    sheet('2026-10')->set('indexes.' . $this->elec->id, '1392');

    sheet('2026-11')
        ->assertSee('Début : 1 392 le 31/10 (fin octobre)', false)
        ->set('indexes.' . $this->elec->id, '1534')
        ->assertSeeText('Consommation du mois : 142 kWh');
});

test('T-09 index inférieur au précédent : refus sous le champ, rien n\'est enregistré', function (): void {
    sheet('2026-10')
        ->set('indexes.' . $this->elec->id, '1240')
        ->assertHasErrors('indexes.' . $this->elec->id)
        ->assertSee('est inférieur à l&#039;index précédent (1 250)', false);

    expect(monthEnd($this->elec, '2026-10'))->toBeNull();

    sheet('2026-10')->set('indexes.' . $this->elec->id, 'mille')->assertHasErrors('indexes.' . $this->elec->id);
});

test('§ 5.3 estimation sur un mois plein : 1 392 + moyenne de 150, 134, 142 = 1 534', function (): void {
    sheet('2026-10')->set('indexes.' . $this->elec->id, '1392');
    $this->history->values = [150_000, 134_000, 142_000];

    sheet('2026-11')
        ->call('estimate', $this->elec->id)
        ->assertHasNoErrors()
        ->assertSet('indexes.' . $this->elec->id, "1\u{00A0}534")
        ->assertSeeText('Estimée')
        ->assertSeeText('moyenne de 150, 134, 142');

    $reading = monthEnd($this->elec, '2026-11');
    expect($reading->is_estimated)->toBeTrue()
        ->and($reading->index_value)->toBe('1534.000')
        ->and($reading->estimation_basis)->toBe('moyenne de 150, 134, 142');
});

test('T-10 estimation après une entrée en cours de mois : moyenne ramenée aux jours restants', function (): void {
    $chambre2 = Unit::factory()->for($this->house)->create(['label' => 'Chambre 2']);
    $meter = $chambre2->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '3480']);
    app(StartOccupancy::class)->execute($chambre2->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(), CarbonImmutable::parse('2026-11-20'), ['electricity' => '3631']);
    $this->history->values = [90_000, 102_000, 96_000];

    sheet('2026-11')->call('estimate', $meter->id)->assertHasNoErrors();

    // 96 × 10 jours ÷ 30 = 32 → 3 631 + 32
    expect(monthEnd($meter, '2026-11')->index_value)->toBe('3663.000');
});

test('EF-25 / T-12 aucune période clôturée : estimation impossible', function (): void {
    sheet('2026-10')
        ->call('estimate', $this->elec->id)
        ->assertHasErrors('indexes.' . $this->elec->id)
        ->assertSeeText('Pas d\'historique pour estimer ce décompteur : saisissez l\'index réel.');

    expect(MeterReading::query()->count())->toBe(0);
});

test('T-18 l\'index réel remplace l\'estimation du même mois ; pas d\'estimation sur un index réel', function (): void {
    sheet('2026-10')->set('indexes.' . $this->elec->id, '1392');
    $this->history->values = [142_000];

    sheet('2026-11')->call('estimate', $this->elec->id)->set('indexes.' . $this->elec->id, '1 530');

    $reading = monthEnd($this->elec, '2026-11');
    expect($reading->is_estimated)->toBeFalse()
        ->and($reading->estimation_basis)->toBeNull()
        ->and($reading->index_value)->toBe('1530.000')
        ->and(MeterReading::query()->where('kind', ReadingKind::MonthEnd)->count())->toBe(2);

    sheet('2026-11')->call('estimate', $this->elec->id)
        ->assertSee('Un index réel est déjà saisi pour ce mois', false);
});

test('EF-17 / T-25 après une estimation : index réel accepté entre le dernier réel et l\'estimé, refusé en dessous du dernier réel', function (): void {
    sheet('2026-10')->set('indexes.' . $this->elec->id, '1392');
    $this->history->values = [142_000];
    sheet('2026-11')->call('estimate', $this->elec->id);

    sheet('2026-12')
        ->set('indexes.' . $this->elec->id, '1380')
        ->assertSee('est inférieur au dernier index réel (1 392)', false)
        ->set('indexes.' . $this->elec->id, '1520')
        ->assertHasNoErrors();

    expect(monthEnd($this->elec, '2026-12')->index_value)->toBe('1520.000');
});

test('T-15 remplacement : dépose de l\'ancien, pose du nouveau, relevé de fin sur le nouveau seulement', function (): void {
    sheet('2026-10')
        ->call('startReplacement', $this->elec->id)
        ->set('replaceDate', '2026-10-15')
        ->set('removalIndex', '1 300')
        ->set('newInstallIndex', '0')
        ->set('newSerial', 'EL-2')
        ->call('replace')
        ->assertHasNoErrors();

    $old = $this->elec->fresh();
    $new = SubMeter::query()->where('replaces_sub_meter_id', $old->id)->sole();
    expect($old->removed_on->toDateString())->toBe('2026-10-15')
        ->and($old->removal_index)->toBe('1300.000')
        ->and($old->readings()->sole()->kind)->toBe(ReadingKind::Removal)
        ->and($new->isActive())->toBeTrue()
        ->and($new->serial_number)->toBe('EL-2')
        ->and($old->needsMonthEndReading(CarbonImmutable::parse('2026-10-01')))->toBeFalse();

    sheet('2026-10')
        ->assertSeeText('Déposé le 15/10/2026 : pas de relevé de fin de mois.')
        ->set('indexes.' . $new->id, '92')
        ->assertHasNoErrors()
        ->assertSeeText('Consommation du mois : 92 kWh');
});

test('remplacement refusé : index de dépose sous le dernier relevé, ou date avant un relevé existant', function (): void {
    sheet('2026-10')->set('indexes.' . $this->elec->id, '1392');

    sheet('2026-11')
        ->call('startReplacement', $this->elec->id)
        ->set('replaceDate', '2026-11-10')->set('removalIndex', '1300')
        ->call('replace')
        ->assertHasErrors('removalIndex');

    sheet('2026-10')
        ->call('startReplacement', $this->elec->id)
        ->set('replaceDate', '2026-10-15')->set('removalIndex', '1300')
        ->call('replace')
        ->assertHasErrors('replaceDate');

    expect($this->elec->fresh()->isActive())->toBeTrue();
});

test('factures : montant et consommation principale saisis à la française, effacés si vides', function (): void {
    sheet('2026-10')
        ->set('amounts.electricity', '62 055')
        ->set('mainConsumptions.electricity', '490')
        ->assertHasNoErrors();

    $bill = UtilityBill::query()->sole();
    expect($bill->utility)->toBe(Utility::Electricity)
        ->and($bill->total_amount)->toBe(62055)
        ->and($bill->main_consumption)->toBe('490.000')
        ->and($bill->main_meter_id)->toBe($this->house->mainMeter(Utility::Electricity)->id);

    sheet('2026-10')
        ->assertSet('amounts.electricity', "62\u{00A0}055")
        ->set('amounts.water', '18 505,5')
        ->assertHasErrors('amounts.water')
        ->set('amounts.electricity', '')
        ->set('mainConsumptions.electricity', '');

    expect(UtilityBill::query()->count())->toBe(0);
});

test('EF-19 / EF-43 la facture est signalée obligatoire ou facultative selon le mode du mois', function (): void {
    // Maison créée en octobre (mode 1) ; prix fixé sans plafond pour l'eau à partir de décembre.
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Water, PricingRule::fixed('600', false), CarbonImmutable::parse('2026-12-01'), $this->owner, true);

    sheet('2026-11')->assertSeeInOrder(['SONEB — eau', 'obligatoire pour clôturer']);
    sheet('2026-12')->assertSeeInOrder(['SBEE — électricité', 'obligatoire pour clôturer', 'SONEB — eau', 'facultative (prix fixé sans plafond)']);
});

test('un mois clôturé refuse toute saisie', function (): void {
    $this->house->billingPeriods()->create(['month' => '2026-10-01', 'status' => PeriodStatus::Closed]);

    sheet('2026-10')
        ->assertSeeText('Clôturé')
        ->set('indexes.' . $this->elec->id, '1392')
        ->assertSee('Octobre 2026 est clôturé : il ne peut plus être modifié.', false);

    expect(MeterReading::query()->count())->toBe(0);
});

test('mois futur ou maison d\'un autre propriétaire : introuvable', function (): void {
    $this->get(route('periodes.show', [$this->house, '2027-01']))->assertNotFound();
    $this->get(route('periodes.show', [$this->house, '2026-13']))->assertNotFound();

    $foreign = House::factory()->create();
    $this->get(route('periodes.show', [$foreign, '2026-10']))->assertNotFound();

    // Un décompteur d'une autre maison ne peut pas être visé depuis cette page.
    $foreignUnit = Unit::factory()->for($foreign)->create();
    $foreignMeter = $foreignUnit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-10-01', 'install_index' => '0']);
    sheet('2026-10')->call('estimate', $foreignMeter->id)->assertNotFound();
});

test('la fiche de la maison mène à la saisie du mois en cours et liste les mois', function (): void {
    sheet('2026-10')->set('indexes.' . $this->elec->id, '1392');

    $this->get(route('maisons.show', $this->house))
        ->assertSeeText('Saisir décembre')
        ->assertSeeText('Octobre 2026')
        ->assertSeeText('Brouillon');
});

test('Milli : les montants de facture s\'affichent avec une espace insécable', function (): void {
    expect(Milli::format(62_055_000))->toBe("62\u{00A0}055");
});
