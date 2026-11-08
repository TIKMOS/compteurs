<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\MarkCreditRefunded;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordPayment;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\ReopenBillingPeriod;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Exceptions\PaymentRejectedException;
use App\Modules\Metering\Queries\HouseBalances;
use App\Modules\Metering\Queries\TenantBalance;
use App\Shared\Enums\PaymentMethod;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 2, tranche 3 — UC-18 : paiements, impayés, remboursements de crédit dus aux locataires sortis.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    $this->occ = [];
    foreach (['Chambre 1' => ['A. Houngbédji', 1250, 412], 'Chambre 2' => ['B. Dossou', 3480, 188]] as $label => [$name, $e, $w]) {
        $unit = Unit::factory()->for($this->house)->create(['label' => $label]);
        $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => $e]);
        $unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-10-01', 'install_index' => $w]);
        $this->occ[$label] = app(StartOccupancy::class)->execute($unit->fresh(), Tenant::factory()->for($this->owner, 'owner')->create(['name' => $name]), CarbonImmutable::parse('2026-10-01'), ['electricity' => "{$e}", 'water' => "{$w}"]);
        $this->units[$label] = $unit;
    }

    // Octobre : 142 + 98 kWh pour 32 000 FCFA ; 9 + 7 m³ pour 9 600 FCFA.
    // Chambre 1 doit 18 933 + 5 400 = 24 333 ; Chambre 2 13 067 + 4 200 = 17 267.
    $period = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    foreach (['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195]] as $label => [$e, $w]) {
        $unit = $this->units[$label]->fresh('subMeters');
        app(RecordMonthEndReading::class)->execute($period, $unit->activeSubMeter(Utility::Electricity), (string) $e);
        app(RecordMonthEndReading::class)->execute($period, $unit->activeSubMeter(Utility::Water), (string) $w);
    }
    app(RecordUtilityBill::class)->execute($period, Utility::Electricity, 32000, null);
    app(RecordUtilityBill::class)->execute($period, Utility::Water, 9600, null);
    $this->october = app(CloseBillingPeriod::class)->execute($period, $this->owner);
    $this->pay = app(RecordPayment::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('paiements partiels : dû, payé, reste ; trop-perçu refusé', function (): void {
    $c1 = $this->occ['Chambre 1'];
    expect(TenantBalance::due($c1, $this->october))->toBe(24333);

    $this->pay->execute($c1, $this->october, 20000, CarbonImmutable::parse('2026-11-05'), PaymentMethod::MtnMomo, $this->owner, 'MoMo 12345');
    expect(TenantBalance::remaining($c1, $this->october))->toBe(4333);

    expect(fn () => $this->pay->execute($c1, $this->october, 5000, CarbonImmutable::parse('2026-11-06'), PaymentMethod::Cash, $this->owner))
        ->toThrow(PaymentRejectedException::class, "Le montant dépasse le reste dû pour octobre 2026 : 4\u{00A0}333 FCFA.");

    $this->pay->execute($c1, $this->october, 4333, CarbonImmutable::parse('2026-11-06'), PaymentMethod::Cash, $this->owner);
    expect(TenantBalance::remaining($c1, $this->october))->toBe(0)
        ->and(fn () => $this->pay->execute($c1, $this->october, 1, CarbonImmutable::parse('2026-11-06'), PaymentMethod::Cash, $this->owner))
        ->toThrow(PaymentRejectedException::class, 'Ce mois est déjà entièrement payé.');
});

test('refus : mois en brouillon, date future, montant nul', function (): void {
    $november = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
    $c1 = $this->occ['Chambre 1'];

    expect(fn () => $this->pay->execute($c1, $november, 100, CarbonImmutable::parse('2026-11-05'), PaymentMethod::Cash, $this->owner))
        ->toThrow(PaymentRejectedException::class, 'Les paiements s\'enregistrent sur un mois clôturé : clôturez d\'abord novembre 2026.')
        ->and(fn () => $this->pay->execute($c1, $this->october, 100, CarbonImmutable::parse('2026-11-25'), PaymentMethod::Cash, $this->owner))
        ->toThrow(PaymentRejectedException::class, 'La date du paiement ne peut pas être dans le futur.')
        ->and(fn () => $this->pay->execute($c1, $this->october, 0, CarbonImmutable::parse('2026-11-05'), PaymentMethod::Cash, $this->owner))
        ->toThrow(PaymentRejectedException::class, 'Le montant doit être positif.');
});

test('suivi de la maison : impayés d\'abord, total à encaisser', function (): void {
    $this->pay->execute($this->occ['Chambre 2'], $this->october, 17267, CarbonImmutable::parse('2026-11-05'), PaymentMethod::Cash, $this->owner);

    $b = app(HouseBalances::class)->execute($this->house);

    expect($b['totalRemaining'])->toBe(24333)
        ->and($b['tenants'][0]['occupancy']->id)->toBe($this->occ['Chambre 1']->id)
        ->and($b['tenants'][0]['remaining'])->toBe(24333)
        ->and($b['tenants'][1]['remaining'])->toBe(0)
        ->and($b['refunds'])->toBe([]);
});

test('remboursement de crédit : listé, marqué effectué, puis bloque la réouverture (H-29)', function (): void {
    $settlement = $this->october->creditSettlements()->forceCreate([
        'occupancy_id' => $this->occ['Chambre 1']->id, 'billing_period_id' => $this->october->id,
        'statement_total' => 24333, 'credit_value' => 30000, 'deducted_amount' => 24333, 'refund_due' => 5667,
    ]);

    $b = app(HouseBalances::class)->execute($this->house);
    expect($b['totalRefunds'])->toBe(5667)
        // Dû = participations − crédit déduit : 24 333 − 24 333 = 0.
        ->and(TenantBalance::due($this->occ['Chambre 1'], $this->october))->toBe(0);

    app(MarkCreditRefunded::class)->execute($settlement, CarbonImmutable::parse('2026-11-10'));

    expect(app(HouseBalances::class)->execute($this->house)['refunds'])->toBe([])
        ->and(app(ReopenBillingPeriod::class)->refusal($this->october->fresh()))->toContain('remboursement de crédit')
        ->and(fn () => app(MarkCreditRefunded::class)->execute($settlement->fresh(), CarbonImmutable::parse('2026-11-10')))
        ->toThrow(PaymentRejectedException::class, 'Ce remboursement est déjà marqué comme effectué.');
});

test('écran des paiements : encaisser, voir le reste, annuler', function (): void {
    $component = Livewire::test('app.paiements.index', ['house' => $this->house])
        ->assertSeeInOrder(['Reste à encaisser', "41\u{00A0}600 FCFA"])
        ->call('startPayment', $this->occ['Chambre 1']->id, $this->october->id, 24333)
        ->assertSet('amount', '24333')
        ->set('amount', '20 000')
        ->set('method', 'moov_money')
        ->call('savePayment')
        ->assertHasNoErrors()
        ->assertSeeText('Paiement enregistré.')
        // Après une action, assertSee lit le HTML rendu (assertSeeInOrder lirait le JSON brut).
        ->assertSee("21\u{00A0}600 FCFA")
        ->assertSeeText('Moov Money');

    $payment = Payment::query()->sole();
    expect($payment->amount)->toBe(20000)
        ->and($payment->paid_on->toDateString())->toBe('2026-11-20')
        ->and($payment->recorded_by)->toBe($this->owner->id);

    $component->call('cancelPayment', $payment->id)->assertSeeText('Paiement annulé.');
    expect(Payment::query()->count())->toBe(0);
});

test('écran : trop-perçu refusé sous le champ ; maison d\'un autre propriétaire introuvable', function (): void {
    Livewire::test('app.paiements.index', ['house' => $this->house])
        ->call('startPayment', $this->occ['Chambre 2']->id, $this->october->id, 17267)
        ->set('amount', '20000')
        ->call('savePayment')
        ->assertHasErrors('amount');

    $this->actingAs(User::factory()->create())->get(route('paiements.index', $this->house))->assertNotFound();
});

test('résultat du mois : statut, reçu et reste affichés par locataire', function (): void {
    $this->pay->execute($this->occ['Chambre 1'], $this->october, 20000, CarbonImmutable::parse('2026-11-05'), PaymentMethod::Cash, $this->owner);

    Livewire::test('app.periodes.recapitulatif', ['house' => $this->house, 'mois' => '2026-10'])
        ->assertSeeInOrder(['Chambre 1 — A. Houngbédji', 'Partiel', "Reçu 20\u{00A0}000 FCFA · reste 4\u{00A0}333 FCFA"])
        ->assertSeeInOrder(['Chambre 2 — B. Dossou', 'Impayé', 'Reçu 0 FCFA · reste 17']);
});

test('statut de chaque répartition clôturée : impayé, partiel, payé ; liste des impayés', function (): void {
    $c1 = $this->occ['Chambre 1'];
    $c2 = $this->occ['Chambre 2'];

    $statuses = fn () => collect(app(HouseBalances::class)->execute($this->house)['unpaid'])
        ->mapWithKeys(fn ($r) => [$r['occupancy']->tenant->name => $r['status']->value])->all();

    expect($statuses())->toBe(['A. Houngbédji' => 'unpaid', 'B. Dossou' => 'unpaid']);

    $this->pay->execute($c1, $this->october, 10000, CarbonImmutable::parse('2026-11-05'), PaymentMethod::MoovMoney, $this->owner);
    $this->pay->execute($c2, $this->october, 17267, CarbonImmutable::parse('2026-11-05'), PaymentMethod::Cash, $this->owner);

    expect($statuses())->toBe(['A. Houngbédji' => 'partial']);

    Livewire::test('app.paiements.index', ['house' => $this->house])
        ->assertSeeInOrder(['Impayés', 'Chambre 1 — A. Houngbédji', 'Octobre 2026', "reste 14\u{00A0}333 FCFA", 'Partiel'])
        ->assertSeeInOrder(['Détail par locataire', 'Chambre 2 — B. Dossou', 'Payé']);
});

test('moyens proposés : espèces, MTN MoMo, Moov Money, autre ; l\'ancien « Mobile Money » n\'est plus proposé', function (): void {
    expect(array_map(fn (PaymentMethod $m) => $m->label(), PaymentMethod::selectable()))
        ->toBe(['Espèces', 'MTN MoMo', 'Moov Money', 'Autre']);

    Livewire::test('app.paiements.index', ['house' => $this->house])
        ->call('startPayment', $this->occ['Chambre 1']->id, $this->october->id, 24333)
        ->assertSee('<option value="mtn_momo">MTN MoMo</option>', false)
        ->assertDontSee('value="mobile_money"', false)
        ->set('method', 'mobile_money')
        ->call('savePayment')
        ->assertHasErrors('method');
});

test('statut : un montant dû nul (crédit qui couvre tout) est payé', function (): void {
    expect(App\Shared\Enums\PaymentStatus::for(0, 0))->toBe(App\Shared\Enums\PaymentStatus::Paid)
        ->and(App\Shared\Enums\PaymentStatus::for(100, 40))->toBe(App\Shared\Enums\PaymentStatus::Partial)
        ->and(App\Shared\Enums\PaymentStatus::for(100, 0))->toBe(App\Shared\Enums\PaymentStatus::Unpaid);
});
