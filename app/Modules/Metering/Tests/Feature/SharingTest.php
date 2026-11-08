<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
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
use App\Modules\Metering\Queries\BuildTenantStatement;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

// Lot 2, tranche 2 — EF-36 / EF-37 : fiche du locataire (PDF) et partage WhatsApp / SMS.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2027-01-31 20:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
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

function closeShared(string $ym, array $indexes, int $elec, int $water, bool $estimateC1Elec = false): BillingPeriod
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

test('EF-36 fiche : tranches avec index, prix appliqué, montants et total', function (): void {
    // Octobre : 142 + 98 = 240 kWh pour 32 000 FCFA ; 9 + 7 = 16 m³ pour 9 600 FCFA.
    $october = closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);

    $s = app(BuildTenantStatement::class)->execute($october, $this->occ['Chambre 1']);

    expect($s->from->toDateString())->toBe('2026-10-01')
        ->and($s->to->toDateString())->toBe('2026-10-31')
        ->and($s->utilities[0]['segments'])->toBe([[
            'from' => "1\u{00A0}250 le 01/10 (entrée)",
            'to' => "1\u{00A0}392 le 31/10 (fin de mois)",
            'consumption' => 142_000,
        ]])
        ->and($s->utilities[0]['priceLabel'])->toBe('Prix unitaire : 133,333 FCFA/kWh — répartition de la facture')
        ->and($s->utilities[0]['amount'])->toBe(18933)
        ->and($s->utilities[1]['amount'])->toBe(5400)
        ->and($s->total)->toBe(24333)
        ->and($s->creditRefunded)->toBeNull();
});

test('EF-37 message court WhatsApp / SMS, au numéro du locataire s\'il est connu', function (): void {
    $october = closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);
    $s = app(BuildTenantStatement::class)->execute($october, $this->occ['Chambre 1']);

    expect($s->shortText())->toBe(implode("\n", [
        'Participation aux frais d\'eau et d\'électricité — octobre 2026',
        'Cotonou Fidjrossè · Chambre 1 · A. Houngbédji',
        "Électricité : 142 kWh = 18\u{00A0}933 FCFA",
        "Eau : 9 m³ = 5\u{00A0}400 FCFA",
        "Total : 24\u{00A0}333 FCFA",
    ]))
        ->and($s->whatsappUrl())->toStartWith('https://wa.me/2290197000101?text=Participation%20aux%20frais')
        ->and($s->smsUrl())->toStartWith('sms:+2290197000101?body=');

    $noPhone = app(BuildTenantStatement::class)->execute($october, $this->occ['Chambre 2']);
    expect($noPhone->whatsappUrl())->toStartWith('https://wa.me/?text=');
});

test('fiche de sortie avec estimation, crédit remboursé et net à payer', function (): void {
    closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);
    closeShared('2026-11', ['Chambre 1' => [null, 430], 'Chambre 2' => [3680, 202]], 30000, 9000, estimateC1Elec: true);
    closeShared('2026-12', ['Chambre 1' => [1520, 432], 'Chambre 2' => [3820, 206]], 20000, 8000);

    app(EndOccupancy::class)->execute($this->occ['Chambre 1'], CarbonImmutable::parse('2027-01-10'), ['electricity' => '1525', 'water' => '433']);
    $january = closeShared('2027-01', ['Chambre 1' => [1525, 433], 'Chambre 2' => [3950, 210]], 26000, 7000);

    $s = app(BuildTenantStatement::class)->execute($january, $this->occ['Chambre 1']->fresh());

    expect($s->to->toDateString())->toBe('2027-01-10')
        ->and($s->utilities[0]['creditApplied'])->toBe(5_000)
        ->and($s->utilities[0]['amount'])->toBe(0)
        ->and($s->creditRefunded)->not->toBeNull()
        ->and($s->shortText())->toContain('Crédit restant remboursé')
        ->toContain('Net à payer');

    $november = BillingPeriod::query()->where('month', '2026-11-01')->sole();
    $estimated = app(BuildTenantStatement::class)->execute($november, $this->occ['Chambre 1']->fresh());
    expect($estimated->utilities[0]['estimated'])->toBeTrue()
        ->and($estimated->utilities[0]['estimationBasis'])->toBe('moyenne de 142')
        ->and($estimated->shortText())->toContain('(estimée)');
});

test('PDF : téléchargé pour un mois clôturé, avec le bon nom de fichier', function (): void {
    Pdf::fake();
    closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);

    $this->get(route('periodes.statement', [$this->house, '2026-10', $this->occ['Chambre 1']->id]))->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->viewName === 'pdf.fiche-locataire'
        && $pdf->downloadName === 'participation-2026-10-chambre-1-a-houngbedji.pdf'
        && $pdf->viewData['s']->total === 24333
        && $pdf->viewData['s']->occupancy->tenant->name === 'A. Houngbédji');
});

test('PDF réel : DomPDF produit un fichier PDF', function (): void {
    closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);

    $response = $this->get(route('periodes.statement', [$this->house, '2026-10', $this->occ['Chambre 1']->id]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('PDF refusé : mois en brouillon, logement d\'une autre maison, autre propriétaire', function (): void {
    app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    $this->get(route('periodes.statement', [$this->house, '2026-10', $this->occ['Chambre 1']->id]))->assertNotFound();

    closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);
    $other = House::factory()->for($this->owner, 'owner')->create();
    $this->get(route('periodes.statement', [$other, '2026-10', $this->occ['Chambre 1']->id]))->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get(route('periodes.statement', [$this->house, '2026-10', $this->occ['Chambre 1']->id]))->assertNotFound();
});

test('écran de résultat : boutons WhatsApp, SMS et fiche PDF une fois le mois clôturé', function (): void {
    closeShared('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]], 32000, 9600);

    Livewire::test('app.periodes.recapitulatif', ['house' => $this->house, 'mois' => '2026-10'])
        ->assertSee('https://wa.me/2290197000101?text=', false)
        ->assertSee('sms:+2290197000101?body=', false)
        ->assertSee(route('periodes.statement', [$this->house, '2026-10', $this->occ['Chambre 1']->id]), false);
});
