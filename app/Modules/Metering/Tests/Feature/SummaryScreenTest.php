<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\CreditSettlement;
use App\Models\House;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\ChangeUtilityPricing;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\EndOccupancy;
use App\Modules\Metering\Actions\EstimateMeterReading;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Data\PricingRule;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 1, tranche 5 — écran de résultat et clôture (UC-13, UC-14, EF-36, EF-42, EF-43), mode 2
// de bout en bout (CONCEPTION.md § 5.8, § 5.9 ; T-36, T-39, T-40, T-44, T-49, T-56).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2027-01-31 20:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    $this->units = [];
    $this->occ = [];

    foreach (['Chambre 1' => ['A. Houngbédji', 1250, 412], 'Chambre 2' => ['B. Dossou', 3480, 188], 'Chambre 3' => ['C. Agossou', 702, 960]] as $label => [$name, $e, $w]) {
        $unit = Unit::factory()->for($this->house)->create(['label' => $label]);
        $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => $e]);
        $unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-10-01', 'install_index' => $w]);
        $this->occ[$label] = app(StartOccupancy::class)->execute($unit->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(['name' => $name]), CarbonImmutable::parse('2026-10-01'), ['electricity' => "{$e}", 'water' => "{$w}"]);
        $this->units[$label] = $unit;
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

function fillMonth(string $ym, array $indexes, ?int $elecBill, ?int $waterBill): BillingPeriod
{
    $period = app(OpenBillingPeriod::class)->execute(test()->house, CarbonImmutable::parse($ym . '-01'));
    foreach ($indexes as $label => [$e, $w]) {
        $unit = test()->units[$label]->fresh('subMeters');
        foreach (['electricity' => $e, 'water' => $w] as $utility => $index) {
            if ($index !== null) {
                app(RecordMonthEndReading::class)->execute($period, $unit->activeSubMeter(Utility::from($utility)), (string) $index);
            }
        }
    }
    app(RecordUtilityBill::class)->execute($period, Utility::Electricity, $elecBill, null);
    app(RecordUtilityBill::class)->execute($period, Utility::Water, $waterBill, null);

    return $period;
}

function fillOctober(?int $elecBill = 62055): BillingPeriod
{
    return fillMonth('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195], 'Chambre 3' => [917, 974]], $elecBill, 18505);
}

function summaryScreen(string $mois = '2026-10')
{
    return Livewire::test('app.periodes.recapitulatif', ['house' => test()->house, 'mois' => $mois]);
}

test('brouillon : calcul en direct, récapitulatif par locataire, bouton de clôture', function (): void {
    fillOctober();

    summaryScreen()
        ->assertSeeText('Brouillon — calcul en direct')
        ->assertSeeText('Prix unitaire : 136,385 FCFA/kWh — répartition de la facture')
        ->assertSeeInOrder(['Chambre 1 — A. Houngbédji', "19\u{00A0}367 FCFA", 'Chambre 2 — B. Dossou', "13\u{00A0}366 FCFA", 'Chambre 3 — C. Agossou', "29\u{00A0}322 FCFA"])
        ->assertSeeText('Récapitulatif par locataire')
        ->assertSeeInOrder(['Chambre 1 — A. Houngbédji', 'Total', "24\u{00A0}919 FCFA"])
        ->assertSeeText('Clôturer octobre 2026')
        ->assertDontSeeText('À compléter avant la clôture');
});

test('UC-14 clôturer depuis l\'écran : mois figé, affichage relu en base', function (): void {
    $october = fillOctober();

    summaryScreen()->call('close')->assertSeeText('Clôturé le 31/01/2027')->assertDontSeeText('Clôturer octobre 2026');

    expect($october->fresh()->status)->toBe(PeriodStatus::Closed);

    // ENF-08 : même affichage une fois figé, sans recalcul.
    summaryScreen()->assertSeeInOrder(['Chambre 3 — C. Agossou', "29\u{00A0}322 FCFA"])
        ->assertSeeText('Prix unitaire : 136,385 FCFA/kWh — répartition de la facture');

    Livewire::test('app.periodes.saisie', ['house' => $this->house, 'mois' => '2026-10'])
        ->assertSeeText('Voir le résultat');
});

test('incomplet : la liste des manques remplace le bouton de clôture', function (): void {
    fillMonth('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], null, 18505);

    summaryScreen()
        ->assertSeeText('À compléter avant la clôture')
        ->assertSeeText('Chambre 3 (électricité) : relevé de fin de mois manquant — saisissez-le ou estimez-le.')
        ->assertDontSeeText('Clôturer octobre 2026')
        ->call('close')
        ->assertSet('closeErrors', fn (array $errors) => in_array('Chambre 3 (eau) : relevé de fin de mois manquant — saisissez-le ou estimez-le.', $errors, true));

    expect(BillingPeriod::query()->sole()->status)->toBe(PeriodStatus::Draft);
});

test('T-36 / T-43 / T-56 mode 2 plafonné à 127,50 pour l\'électricité, eau en mode 1', function (): void {
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Electricity, PricingRule::fixed('127.50'), CarbonImmutable::parse('2026-10-01'), $this->owner, true);
    $october = fillOctober();

    summaryScreen()
        ->assertSeeText('Prix appliqué : 127,50 FCFA/kWh — prix fixé (prix réel : 136,385)')
        ->assertSeeInOrder(["18\u{00A0}105 FCFA", "12\u{00A0}495 FCFA", "27\u{00A0}413 FCFA"])
        ->assertSeeInOrder(['Total des participations des locataires', "58\u{00A0}013 FCFA", 'Reste à la charge du propriétaire', "4\u{00A0}042 FCFA"])
        ->assertSeeInOrder(['Participation aux frais d\'eau', "5\u{00A0}552 FCFA", "4\u{00A0}318 FCFA", "8\u{00A0}635 FCFA"])
        ->call('close');

    $bill = $october->bills()->where('utility', 'electricity')->sole();
    expect($bill->pricing_mode)->toBe('fixed_price')
        ->and($bill->fixed_unit_price)->toBe('127.50')
        ->and($bill->unit_price)->toBe('255/2')
        ->and($bill->actual_unit_price)->toBe('1773/13')
        ->and($bill->price_source)->toBe('fixed')
        ->and($bill->charges()->sum('amount'))->toBe(58013);
});

test('T-39 / T-40 mode 2 sans plafond : écart d\'encaissement affiché, clôture même sans facture', function (): void {
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Electricity, PricingRule::fixed('150', false), CarbonImmutable::parse('2026-10-01'), $this->owner, true);
    fillOctober();

    summaryScreen()
        ->assertSeeText('Prix appliqué : 150,00 FCFA/kWh — prix fixé, sans plafond')
        ->assertSeeInOrder(["21\u{00A0}300 FCFA", "14\u{00A0}700 FCFA", "32\u{00A0}250 FCFA"])
        ->assertSeeInOrder(["Écart d'encaissement (information)", "+6\u{00A0}195 FCFA"]);

    // Sans facture : clôture permise, colonnes de facture nulles (T-40).
    app(RecordUtilityBill::class)->execute(BillingPeriod::query()->sole(), Utility::Electricity, null, null);
    summaryScreen()->assertSeeText('Facture : non renseignée')->call('close');

    $bill = BillingPeriod::query()->sole()->bills()->where('utility', 'electricity')->sole();
    expect($bill->total_amount)->toBeNull()
        ->and($bill->actual_unit_price)->toBeNull()
        ->and($bill->charges()->sum('amount'))->toBe(68250);
});

test('T-44 / T-49 mode 2 en novembre : vacance, estimation, puis crédit valorisé à 127,50 et soldé à la sortie', function (): void {
    app(CloseBillingPeriod::class)->execute(fillOctober(), $this->owner);
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Electricity, PricingRule::fixed('127.50'), CarbonImmutable::parse('2026-11-01'), $this->owner, true);

    // Novembre (§ 5.9) : B. Dossou sort le 15/11, Chambre 2 vacante, D. Kpadonou entre le 20/11.
    app(EndOccupancy::class)->execute($this->occ['Chambre 2'], CarbonImmutable::parse('2026-11-15'), ['electricity' => '3626', 'water' => '199']);
    app(StartOccupancy::class)->execute($this->units['Chambre 2']->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'D. Kpadonou']), CarbonImmutable::parse('2026-11-20'), ['electricity' => '3631', 'water' => '199.5']);
    $november = fillMonth('2026-11', ['Chambre 1' => [null, 430], 'Chambre 2' => [3680, '202.5'], 'Chambre 3' => [1118, 987]], 58900, 17270);
    app(EstimateMeterReading::class)->execute($november, $this->units['Chambre 1']->fresh('subMeters')->activeSubMeter(Utility::Electricity));

    summaryScreen('2026-11')
        // Payeurs d'un logement dans l'ordre chronologique : sortant, vacance, entrant.
        ->assertSeeInOrder(['Chambre 1 — A. Houngbédji', "18\u{00A0}105 FCFA", 'Chambre 2 — B. Dossou', "6\u{00A0}120 FCFA", 'Chambre 2 — Propriétaire / vacant', '638 FCFA', 'Chambre 2 — D. Kpadonou', "6\u{00A0}248 FCFA", 'Chambre 3 — C. Agossou', "25\u{00A0}628 FCFA"])
        ->assertSeeInOrder(['Total des participations des locataires', "56\u{00A0}101 FCFA", 'Part du propriétaire (logements vacants)', '638 FCFA', 'Reste à la charge du propriétaire', "2\u{00A0}799 FCFA"])
        ->call('close');

    // Décembre : réel 1 520 < 1 534 estimé → 14 kWh de crédit au prix appliqué de novembre (255/2).
    app(CloseBillingPeriod::class)->execute(fillMonth('2026-12', ['Chambre 1' => [1520, 432], 'Chambre 2' => [3820, 206], 'Chambre 3' => [1318, 1000]], 60000, 18000), $this->owner);

    // Janvier : sortie le 10/01 à 1 525, brute 5, reste 9 kWh × 127,50 = 1 147,5 → 1 148 FCFA.
    app(EndOccupancy::class)->execute($this->occ['Chambre 1'], CarbonImmutable::parse('2027-01-10'), ['electricity' => '1525', 'water' => '433']);
    fillMonth('2027-01', ['Chambre 1' => [1525, 433], 'Chambre 2' => [3950, 210], 'Chambre 3' => [1500, 1010]], 55000, 17000);

    summaryScreen('2027-01')
        ->assertSeeInOrder(['Chambre 1 — A. Houngbédji', 'Crédit restant remboursé', 'Net à payer'])
        ->call('close');

    expect(CreditSettlement::query()->sole()->credit_value)->toBe(1148);
});

test('un autre propriétaire ne voit pas le résultat ; mois futur introuvable', function (): void {
    fillOctober();

    $this->get(route('periodes.summary', [$this->house, '2027-02']))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('periodes.summary', [$this->house, '2026-10']))->assertNotFound();
});
