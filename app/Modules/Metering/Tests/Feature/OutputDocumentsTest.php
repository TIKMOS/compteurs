<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
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
use App\Modules\Metering\Actions\RecordPayment;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Data\TenantStatement;
use App\Modules\Metering\Queries\BuildExitStatement;
use App\Modules\Metering\Queries\BuildTenantStatement;
use App\Shared\Enums\PaymentMethod;
use App\Shared\Enums\Utility;
use App\Shared\Support\FrenchNumber;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

// Documents de sortie : fiche mensuelle, fiche de sortie, récapitulatif du mois, export Excel,
// partage WhatsApp par lien signé et temporaire.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè', 'address' => 'Fidjrossè, Cotonou']);
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    $this->units = [];
    $this->occ = [];
    foreach (['Chambre 1' => ['A. Houngbédji', '+2290197000101', 1250, 412], 'Chambre 2' => ['B. Dossou', null, 3480, 188]] as $label => [$name, $phone, $e, $w]) {
        $unit = Unit::factory()->for($this->house)->create(['label' => $label]);
        $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => $e]);
        $unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-10-01', 'install_index' => $w]);
        $tenant = Tenant::factory()->for($this->owner, 'owner')->create(['name' => $name, 'phone' => $phone]);
        $this->occ[$label] = app(StartOccupancy::class)->execute($unit->fresh(), $tenant, CarbonImmutable::parse('2026-10-01'), ['electricity' => "{$e}", 'water' => "{$w}"]);
        $this->units[$label] = $unit;
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

function closeDocMonth(string $ym, array $indexes, int $elec, int $water, bool $estimateC1Elec = false): BillingPeriod
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
    if ($estimateC1Elec) {
        app(EstimateMeterReading::class)->execute($period, test()->units['Chambre 1']->fresh('subMeters')->activeSubMeter(Utility::Electricity));
    }
    app(RecordUtilityBill::class)->execute($period, Utility::Electricity, $elec, null);
    app(RecordUtilityBill::class)->execute($period, Utility::Water, $water, null);

    return app(CloseBillingPeriod::class)->execute($period, test()->owner);
}

/** Octobre : 142 + 98 kWh pour 32 000 FCFA ; 9 + 7 m³ pour 9 600 FCFA. Chambre 1 = 24 333 FCFA. */
function closeOctober(): BillingPeriod
{
    return closeDocMonth('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);
}

test('montant en toutes lettres, orthographe traditionnelle', function (int $n, string $words): void {
    expect(FrenchNumber::toWords($n))->toBe($words);
})->with([
    [0, 'zéro'], [21, 'vingt et un'], [71, 'soixante et onze'], [80, 'quatre-vingts'], [81, 'quatre-vingt-un'],
    [91, 'quatre-vingt-onze'], [200, 'deux cents'], [201, 'deux cent un'], [1000, 'mille'], [80000, 'quatre-vingt mille'],
    [200000, 'deux cent mille'], [24333, 'vingt-quatre mille trois cent trente-trois'], [2000000, 'deux millions'],
]);

test('date limite de paiement : jour choisi du mois suivant, ou 7 jours après une clôture tardive', function (): void {
    $october = closeOctober();
    expect($this->house->fresh()->paymentDueDate($october)->toDateString())->toBe('2026-11-10');

    $this->house->update(['payment_due_day' => 5]);
    // Clôturé le 02/11 : le 5 est trop proche, la limite passe au 09/11.
    expect($this->house->fresh()->paymentDueDate($october)->toDateString())->toBe('2026-11-09');

    Livewire::test('app.maisons.modifier', ['house' => $this->house->fresh()])
        ->set('paymentDueDay', 31)->call('save')->assertHasErrors('paymentDueDay')
        ->set('paymentDueDay', 15)->call('save')->assertHasNoErrors();
    expect($this->house->fresh()->payment_due_day)->toBe(15);
});

test('fiche mensuelle : index, mode et prix, crédit, total en lettres, payé, reste, date limite', function (): void {
    $october = closeOctober();
    app(RecordPayment::class)->execute($this->occ['Chambre 1'], $october, 10000, CarbonImmutable::parse('2026-11-02'), PaymentMethod::MtnMomo, $this->owner);

    $s = app(BuildTenantStatement::class)->execute($october, $this->occ['Chambre 1']);
    expect($s->total)->toBe(24333)
        ->and($s->paid)->toBe(10000)
        ->and($s->remaining())->toBe(14333)
        ->and($s->dueDate->toDateString())->toBe('2026-11-10');

    $html = view('pdf.fiche-locataire', ['s' => $s])->render();
    expect($html)
        ->toContain("Participation aux frais d'eau et d'électricité")
        ->toContain('Cotonou Fidjrossè')->toContain('Chambre 1')->toContain('A. Houngbédji')->toContain('Octobre 2026')
        ->toContain('Index ancien')->toContain('Index nouveau')
        ->toContain("1\u{00A0}250 le 01/10 (entrée)")->toContain("1\u{00A0}392 le 31/10 (fin de mois)")
        ->toContain('Crédit de consommation restant')
        ->toContain('Mode de calcul et prix : Prix unitaire : 133,333 FCFA/kWh — répartition de la facture')
        ->toContain("24\u{00A0}333 FCFA")
        ->toContain('Soit vingt-quatre mille trois cent trente-trois francs CFA.')
        ->toContain("10\u{00A0}000 FCFA")
        ->toContain("14\u{00A0}333 FCFA")
        ->toContain('Date limite de paiement')->toContain('10/11/2026')
        ->not->toContain('Facture du')->not->toContain('vente')->not->toContain('achat');
});

test('fiche mensuelle : mention « estimé » et crédit restant', function (): void {
    closeOctober();
    CarbonImmutable::setTestNow('2026-12-02 09:00:00');
    $november = closeDocMonth('2026-11', ['Chambre 1' => [null, 430], 'Chambre 2' => [3680, 202]], 30000, 9000, estimateC1Elec: true);
    CarbonImmutable::setTestNow('2027-01-02 09:00:00');
    $december = closeDocMonth('2026-12', ['Chambre 1' => [1520, 432], 'Chambre 2' => [3820, 206]], 20000, 8000);

    $estimated = view('pdf.fiche-locataire', ['s' => app(BuildTenantStatement::class)->execute($november, $this->occ['Chambre 1'])])->render();
    expect($estimated)->toContain('ESTIMÉ')->toContain('Index nouveau estimé (moyenne de 142)');

    // Réel de décembre (1 520) sous l'estimation de novembre (1 534) : 14 kWh de crédit, déduits ensuite.
    $s = app(BuildTenantStatement::class)->execute($december, $this->occ['Chambre 1']);
    expect($s->utilities[0]['creditBalance'])->toBe(14_000)
        ->and(view('pdf.fiche-locataire', ['s' => $s])->render())->toContain('RÉGULARISATION')->toContain('14 kWh');
});

test('fiche de sortie : dernier relevé, crédit converti en FCFA, à rembourser au locataire', function (): void {
    closeOctober();
    // Novembre estimé très au-dessus du réel : gros crédit, presque rien consommé avant la sortie.
    CarbonImmutable::setTestNow('2026-12-02 09:00:00');
    closeDocMonth('2026-11', ['Chambre 1' => [null, 430], 'Chambre 2' => [3680, 202]], 30000, 9000, estimateC1Elec: true);
    CarbonImmutable::setTestNow('2027-01-02 09:00:00');
    closeDocMonth('2026-12', ['Chambre 1' => [1400, 432], 'Chambre 2' => [3820, 206]], 20000, 8000);

    CarbonImmutable::setTestNow('2027-01-20 09:00:00');
    expect(app(BuildExitStatement::class)->execute($this->occ['Chambre 1']->fresh()))->toBeNull();
    app(EndOccupancy::class)->execute($this->occ['Chambre 1']->fresh(), CarbonImmutable::parse('2027-01-05'), ['electricity' => '1402', 'water' => '433']);
    // Sortie enregistrée mais janvier pas clôturé : pas encore de fiche.
    expect(app(BuildExitStatement::class)->execute($this->occ['Chambre 1']->fresh()))->toBeNull();

    CarbonImmutable::setTestNow('2027-02-02 09:00:00');
    $january = closeDocMonth('2027-01', ['Chambre 1' => [1402, 433], 'Chambre 2' => [3950, 210]], 26000, 7000);

    // Dernière fiche mensuelle : la consommation de janvier est couverte par le crédit.
    $last = view('pdf.fiche-locataire', ['s' => app(BuildTenantStatement::class)->execute($january, $this->occ['Chambre 1']->fresh())])->render();
    expect($last)->toContain('Crédit de consommation déduit')->toContain('Crédit restant remboursé (sortie)');

    $x = app(BuildExitStatement::class)->execute($this->occ['Chambre 1']->fresh());
    expect($x)->not->toBeNull()
        ->and($x->readings)->toHaveCount(2)
        ->and($x->readings[0]['index'])->toBe(1_402_000)
        ->and($x->lots)->not->toBeEmpty()
        ->and($x->creditValue)->toBeGreaterThan(0)
        ->and($x->deducted + $x->refundDue)->toBe($x->creditValue)
        ->and($x->refundDue)->toBeGreaterThan(0)
        ->and($x->lastNet())->toBe(0);

    $html = view('pdf.fiche-sortie', ['x' => $x])->render();
    expect($html)->toContain('Fiche de sortie du locataire')
        ->toContain("1\u{00A0}402 kWh")
        ->toContain('Crédit de consommation converti en FCFA')
        ->toContain('estimation de novembre 2026')
        ->toContain('À rembourser au locataire')
        ->toContain(TenantStatement::fcfa($x->refundDue))
        ->toContain('Soit ' . TenantStatement::inWords($x->refundDue));

    Pdf::fake();
    $this->get(route('sorties.pdf', [$this->house, $this->occ['Chambre 1']->id]))->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->viewName === 'pdf.fiche-sortie'
        && $pdf->downloadName === 'fiche-de-sortie-chambre-1-a-houngbedji-2027-01-05.pdf');

    // La fiche locataire propose le PDF de sortie.
    Livewire::test('app.locataires.detail', ['tenant' => $this->occ['Chambre 1']->tenant])->assertSee('Fiche de sortie (PDF)');
});

test('récapitulatif du mois : tous les logements, totaux, facture principale, écart en mode 2', function (): void {
    app(ChangeUtilityPricing::class)->execute($this->house, Utility::Water, PricingRule::fixed('650', false), CarbonImmutable::parse('2026-10-01'), $this->owner, true);
    $october = closeOctober();

    Pdf::fake();
    $this->get(route('periodes.summary.pdf', [$this->house, '2026-10']))->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->viewName === 'pdf.recapitulatif-mois'
        && $pdf->downloadName === 'recapitulatif-2026-10-cotonou-fidjrosse.pdf'
        && count($pdf->viewData['rows']) === 2);

    $controller = app(App\Modules\Metering\Http\Controllers\MonthSummaryPdfController::class);
    $html = view('pdf.recapitulatif-mois', $controller($this->house, '2026-10')->viewData)->render();

    // Eau en mode 2 sans plafond : 16 m³ × 650 = 10 400 FCFA pour une facture de 9 600 FCFA.
    expect($html)->toContain('Chambre 1')->toContain('Chambre 2')->toContain('A. Houngbédji')->toContain('B. Dossou')
        ->toContain('Facture SBEE (électricité)')->toContain('Facture SONEB (eau)')
        ->toContain("32\u{00A0}000 FCFA")->toContain("9\u{00A0}600 FCFA")
        ->toContain('Écart entre participations et facture (mode 2 sans plafond)')
        ->toContain('+ 800 FCFA')
        ->toContain('Encaissements au');
});

test('les fiches PDF sont de vrais PDF (DomPDF)', function (): void {
    closeOctober();
    foreach ([route('periodes.statement', [$this->house, '2026-10', $this->occ['Chambre 1']->id]), route('periodes.summary.pdf', [$this->house, '2026-10'])] as $url) {
        $body = $this->get($url)->assertOk()->getContent();
        expect(substr($body, 0, 5))->toBe('%PDF-');
    }
});

test('export Excel : période choisie, trois feuilles, nombres exploitables', function (): void {
    closeOctober();
    CarbonImmutable::setTestNow('2026-12-02 09:00:00');
    closeDocMonth('2026-11', ['Chambre 1' => [1500, 430], 'Chambre 2' => [3680, 202]], 30000, 9000);

    $response = $this->get(route('maisons.export.xlsx', [$this->house, 'du' => '2026-11', 'au' => '2026-11']))->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))->toContain('historique-cotonou-fidjrosse-2026-11-2026-11.xlsx');

    $reader = new Reader;
    $reader->open($response->getFile()->getPathname());
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    expect(array_keys($sheets))->toBe(['Participations', 'Factures', 'Encaissements'])
        // En-tête + 2 logements × 2 fluides, pour novembre seulement.
        ->and($sheets['Participations'])->toHaveCount(5)
        ->and(array_unique(array_column(array_slice($sheets['Participations'], 1), 0)))->toBe(['2026-11'])
        ->and($sheets['Participations'][1][1])->toBe('Chambre 1')
        ->and($sheets['Participations'][1][7])->toEqual(108)
        ->and($sheets['Factures'])->toHaveCount(3)
        ->and($sheets['Encaissements'][1][2])->toBe('A. Houngbédji');

    $this->get(route('maisons.export.xlsx', [$this->house, 'du' => '2026-12', 'au' => '2026-10']))->assertSessionHasErrors('du');
});

test('partage WhatsApp : numéro +229, montant, reste à payer et lien signé valable 7 jours', function (): void {
    $october = closeOctober();
    $s = app(BuildTenantStatement::class)->execute($october, $this->occ['Chambre 1']);

    $message = $s->whatsappMessage();
    expect($s->whatsappUrl())->toStartWith('https://wa.me/2290197000101?text=')
        ->and($message)->toContain('Bonjour A. Houngbédji,')
        ->toContain("Votre participation aux frais d'eau et d'électricité d'octobre 2026 (Chambre 1, Cotonou Fidjrossè) : 24\u{00A0}333 FCFA.")
        ->toContain('avant le 10/11/2026')
        ->toContain('lien valable 7 jours')
        ->toContain('/fiche/' . $this->occ['Chambre 1']->id . '/2026-10?expires=')
        ->toContain('&signature=');

    preg_match('#https?://\S+#', $message, $m);
    $link = $m[0];

    auth()->logout();
    Pdf::fake();
    $this->get($link)->assertOk();
    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->viewName === 'pdf.fiche-locataire' && $pdf->viewData['s']->total === 24333);

    // Lien modifié : refusé.
    $this->get(str_replace('/2026-10?', '/2026-09?', $link))->assertForbidden()->assertSeeText("Ce lien n'est plus valable", false);

    // Après 7 jours : refusé.
    CarbonImmutable::setTestNow('2026-11-09 09:00:01');
    $this->travelTo(CarbonImmutable::now());
    $this->get($link)->assertForbidden()->assertSeeText('valables 7 jours');
});

test('boutons : partage WhatsApp, récapitulatif PDF, export Excel', function (): void {
    closeOctober();

    Livewire::test('app.periodes.recapitulatif', ['house' => $this->house, 'mois' => '2026-10'])
        ->assertSee('Partager par WhatsApp')
        ->assertSee('Récapitulatif du mois (PDF)')
        ->assertSee('https://wa.me/2290197000101', false);

    Livewire::test('app.maisons.tableau-de-bord', ['house' => $this->house])
        ->assertSee('Télécharger (Excel)')
        ->assertSee(route('maisons.export.xlsx', $this->house), false);
});

test('documents d\'une autre maison : introuvables', function (): void {
    closeOctober();
    $this->actingAs(User::factory()->create());

    $this->get(route('periodes.summary.pdf', [$this->house, '2026-10']))->assertNotFound();
    $this->get(route('sorties.pdf', [$this->house, $this->occ['Chambre 1']->id]))->assertNotFound();
    $this->get(route('maisons.export.xlsx', $this->house))->assertNotFound();
});
