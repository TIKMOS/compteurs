<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\Charge;
use App\Models\ConsumptionCredit;
use App\Models\CreditSettlement;
use App\Models\House;
use App\Models\Occupancy;
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
use App\Modules\Metering\Exceptions\IncompletePeriodException;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;

// Lot 1, tranche 5 — CONCEPTION.md § 5.2 à § 5.6 rejoués de bout en bout par la base :
// saisie, calcul (EF-26 à EF-30), clôture (EF-31, EF-32), estimation sur historique réel,
// crédit de consommation (EF-24) et solde à la sortie.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2027-01-31 20:00:00');
    $this->owner = User::factory()->create();
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $this->house->pricings()->update(['effective_from' => '2026-08-01']);

    $this->units = [];
    $this->occ = [];
    foreach ([
        'Chambre 1' => ['A. Houngbédji', 966, 394],
        'Chambre 2' => ['B. Dossou', 3290, 174],
        'Chambre 3' => ['C. Agossou', 287, 932],
    ] as $label => [$name, $elec, $water]) {
        $unit = Unit::factory()->for($this->house)->create(['label' => $label]);
        $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-08-01', 'install_index' => $elec]);
        $unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-08-01', 'install_index' => $water]);
        $tenant = Tenant::factory()->for($this->owner, 'owner')->create(['name' => $name]);
        $this->occ[$label] = app(StartOccupancy::class)->execute($unit->fresh(), $tenant, CarbonImmutable::parse('2026-08-01'), ['electricity' => "{$elec}", 'water' => "{$water}"]);
        $this->units[$label] = $unit;
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

function period(string $ym): BillingPeriod
{
    return app(OpenBillingPeriod::class)->execute(test()->house, CarbonImmutable::parse($ym . '-01'));
}

/**
 * @param array<string, array{0: int|string|null, 1: int|string|null}> $indexes [électricité, eau] par logement
 */
function enterMonth(string $ym, array $indexes, ?int $electricityBill, ?int $waterBill): BillingPeriod
{
    $period = period($ym);
    foreach ($indexes as $label => [$elec, $water]) {
        $unit = test()->units[$label]->fresh('subMeters');
        foreach (['electricity' => $elec, 'water' => $water] as $utility => $index) {
            if ($index !== null) {
                app(RecordMonthEndReading::class)->execute($period, $unit->activeSubMeter(Utility::from($utility)), (string) $index);
            }
        }
    }
    app(RecordUtilityBill::class)->execute($period, Utility::Electricity, $electricityBill, null);
    app(RecordUtilityBill::class)->execute($period, Utility::Water, $waterBill, null);

    return $period;
}

function closeMonth(BillingPeriod $period): BillingPeriod
{
    return app(CloseBillingPeriod::class)->execute($period, test()->owner);
}

/**
 * Montants figés d'un fluide : « Chambre 2 — B. Dossou » => 6 353 ; « Chambre 2 — vacant » => 662.
 *
 * @return array<string, int>
 */
function frozen(BillingPeriod $period, Utility $utility): array
{
    $bill = $period->bills()->where('utility', $utility)->sole();

    return $bill->charges()->with(['unit', 'occupancy.tenant'])->get()
        ->mapWithKeys(fn (Charge $c) => [$c->unit->label . ' — ' . ($c->occupancy?->tenant->name ?? 'vacant') => $c->amount])
        ->sortKeys()
        ->all();
}

function closeAugustToOctober(): BillingPeriod
{
    closeMonth(enterMonth('2026-08', ['Chambre 1' => [1116, 402], 'Chambre 2' => [3382, 181], 'Chambre 3' => [492, 946]], 50000, 15000));
    closeMonth(enterMonth('2026-09', ['Chambre 1' => [1250, 412], 'Chambre 2' => [3480, 188], 'Chambre 3' => [702, 960]], 55000, 16000));

    return closeMonth(enterMonth('2026-10', ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195], 'Chambre 3' => [917, 974]], 62055, 18505));
}

test('T-01 / T-02 octobre : montants figés à la clôture, Σ = facture, prix unitaire figé', function (): void {
    $october = closeAugustToOctober();

    expect($october->fresh()->status)->toBe(PeriodStatus::Closed)
        ->and($october->fresh()->closed_by)->toBe($this->owner->id)
        ->and(frozen($october, Utility::Electricity))->toBe([
            'Chambre 1 — A. Houngbédji' => 19367,
            'Chambre 2 — B. Dossou' => 13366,
            'Chambre 3 — C. Agossou' => 29322,
        ])
        ->and(frozen($october, Utility::Water))->toBe([
            'Chambre 1 — A. Houngbédji' => 5552,
            'Chambre 2 — B. Dossou' => 4318,
            'Chambre 3 — C. Agossou' => 8635,
        ]);

    $bill = $october->bills()->where('utility', Utility::Electricity)->sole();
    expect($bill->unit_price)->toBe('1773/13')
        ->and($bill->actual_unit_price)->toBe('1773/13')
        ->and($bill->price_source)->toBe('actual')
        ->and($bill->pricing_mode)->toBe('bill_split')
        ->and($bill->total_consumption)->toBe('455.000')
        ->and($bill->charges()->sum('amount'))->toBe(62055)
        ->and($bill->charges()->where('unit_id', $this->units['Chambre 3']->id)->sole()->rounding_adjustment)->toBe(-1);
});

test('T-03 novembre : sortie, vacance, entrée et estimation sur l\'historique réel (§ 5.3)', function (): void {
    closeAugustToOctober();

    app(EndOccupancy::class)->execute($this->occ['Chambre 2'], CarbonImmutable::parse('2026-11-15'), ['electricity' => '3626', 'water' => '199']);
    $kpadonou = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'D. Kpadonou']);
    app(StartOccupancy::class)->execute($this->units['Chambre 2']->fresh(), $kpadonou, CarbonImmutable::parse('2026-11-20'), ['electricity' => '3631', 'water' => '199.5']);

    $november = enterMonth('2026-11', ['Chambre 2' => [3680, '202.5'], 'Chambre 3' => [1118, 987]], 58900, 17270);
    $chambre1 = $this->units['Chambre 1']->fresh('subMeters');
    $estimated = app(EstimateMeterReading::class)->execute($november, $chambre1->activeSubMeter(Utility::Electricity));
    app(EstimateMeterReading::class)->execute($november, $chambre1->activeSubMeter(Utility::Water));

    expect($estimated->index_value)->toBe('1534.000')
        ->and($estimated->estimation_basis)->toBe('moyenne de 150, 134, 142');

    closeMonth($november);

    expect(frozen($november, Utility::Electricity))->toBe([
        'Chambre 1 — A. Houngbédji' => 18795,
        'Chambre 2 — B. Dossou' => 6353,
        'Chambre 2 — D. Kpadonou' => 6486,
        'Chambre 2 — vacant' => 662,
        'Chambre 3 — C. Agossou' => 26604,
    ])->and(frozen($november, Utility::Water))->toBe([
        'Chambre 1 — A. Houngbédji' => 5269,
        'Chambre 2 — B. Dossou' => 2342,
        'Chambre 2 — D. Kpadonou' => 1756,
        'Chambre 2 — vacant' => 293,
        'Chambre 3 — C. Agossou' => 7610,
    ]);

    $estimatedCharge = Charge::query()->where('unit_id', $this->units['Chambre 1']->id)
        ->whereHas('utilityBill', fn ($q) => $q->where('billing_period_id', $november->id)->where('utility', 'electricity'))->sole();
    expect($estimatedCharge->is_estimated)->toBeTrue();
});

test('T-08 décembre : surestimation → crédit de 14 kWh au prix de novembre ; § 5.6 A sortie en janvier', function (): void {
    closeAugustToOctober();
    $november = enterMonth('2026-11', ['Chambre 2' => [3680, 202], 'Chambre 3' => [1118, 987]], 58900, 17270);
    $c1 = $this->units['Chambre 1']->fresh('subMeters');
    app(EstimateMeterReading::class)->execute($november, $c1->activeSubMeter(Utility::Electricity));
    app(RecordMonthEndReading::class)->execute($november, $c1->activeSubMeter(Utility::Water), '430');
    closeMonth($november);
    $novemberPrice = $november->bills()->where('utility', 'electricity')->sole()->unit_price;

    $december = closeMonth(enterMonth('2026-12', ['Chambre 1' => [1520, 432], 'Chambre 2' => [3820, 206], 'Chambre 3' => [1318, 1000]], 60000, 18000));

    expect(frozen($december, Utility::Electricity))->toBe([
        'Chambre 1 — A. Houngbédji' => 0,
        'Chambre 2 — B. Dossou' => 24706,
        'Chambre 3 — C. Agossou' => 35294,
    ]);

    $lot = ConsumptionCredit::query()->sole();
    expect($lot->quantity)->toBe('14.000')
        ->and($lot->unit_price)->toBe($novemberPrice)
        ->and($lot->occupancy_id)->toBe($this->occ['Chambre 1']->id)
        ->and($lot->originPeriod->month->format('Y-m'))->toBe('2026-11');

    // Janvier : A. Houngbédji sort le 10/01 à 1 525 (brute 5, crédit déduit 5, reste 9 kWh soldés).
    app(EndOccupancy::class)->execute($this->occ['Chambre 1'], CarbonImmutable::parse('2027-01-10'), ['electricity' => '1525', 'water' => '433']);
    $january = enterMonth('2027-01', ['Chambre 1' => [1525, 433], 'Chambre 2' => [3950, 210], 'Chambre 3' => [1500, 1010]], 55000, 17000);

    $preview = app(ComputeBillingPeriod::class)->execute($january);
    $settlement = $preview->settlements[$this->occ['Chambre 1']->id];
    expect($settlement->creditValue)->toBe(1191);

    closeMonth($january);

    $saved = CreditSettlement::query()->sole();
    $statement = Charge::query()->where('occupancy_id', $this->occ['Chambre 1']->id)
        ->whereHas('utilityBill', fn ($q) => $q->where('billing_period_id', $january->id))->sum('amount');
    expect($saved->credit_value)->toBe(1191)
        ->and($saved->statement_total)->toBe((int) $statement)
        ->and($saved->deducted_amount)->toBe(min(1191, (int) $statement))
        ->and($saved->refund_due)->toBe(1191 - min(1191, (int) $statement))
        ->and($lot->fresh()->remaining())->toBe(0);
});

test('EF-31 clôture refusée : relevé manquant, facture manquante, mois précédent en brouillon', function (): void {
    enterMonth('2026-08', ['Chambre 1' => [1116, 402], 'Chambre 2' => [3382, 181]], null, 15000);
    $september = period('2026-09');

    try {
        closeMonth(period('2026-08'));
        $this->fail('La clôture aurait dû être refusée.');
    } catch (IncompletePeriodException $e) {
        expect($e->problems)->toContain('Chambre 3 (électricité) : relevé de fin de mois manquant — saisissez-le ou estimez-le.')
            ->toContain('Chambre 3 (eau) : relevé de fin de mois manquant — saisissez-le ou estimez-le.');
    }

    app(RecordMonthEndReading::class)->execute(period('2026-08'), $this->units['Chambre 3']->fresh('subMeters')->activeSubMeter(Utility::Electricity), '492');
    app(RecordMonthEndReading::class)->execute(period('2026-08'), $this->units['Chambre 3']->fresh('subMeters')->activeSubMeter(Utility::Water), '946');

    expect(app(ComputeBillingPeriod::class)->execute(period('2026-08'))->problems)
        ->toBe(['Facture d\'électricité d\'août 2026 manquante.'])
        ->and(app(ComputeBillingPeriod::class)->execute($september)->problems)
        ->toContain('Clôturez d\'abord août 2026 : les mois se clôturent dans l\'ordre.');

    expect(BillingPeriod::query()->where('status', PeriodStatus::Closed)->count())->toBe(0);
});

test('EF-32 un mois clôturé est figé : plus de relevé, plus de facture, plus de seconde clôture', function (): void {
    $october = closeAugustToOctober();

    expect(fn () => app(RecordMonthEndReading::class)->execute($october, $this->units['Chambre 1']->fresh('subMeters')->activeSubMeter(Utility::Electricity), '1400'))
        ->toThrow(App\Modules\Metering\Exceptions\PeriodClosedException::class)
        ->and(fn () => app(RecordUtilityBill::class)->execute($october, Utility::Electricity, 1, null))
        ->toThrow(App\Modules\Metering\Exceptions\PeriodClosedException::class)
        ->and(fn () => closeMonth($october))
        ->toThrow(App\Modules\Metering\Exceptions\PeriodClosedException::class);
});

test('Occupancy : les participations figées pointent vers les bonnes occupations', function (): void {
    closeAugustToOctober();

    expect(Charge::query()->whereNull('occupancy_id')->count())->toBe(0)
        ->and(Charge::query()->distinct()->pluck('occupancy_id')->sort()->values()->all())
        ->toBe(Occupancy::query()->orderBy('id')->pluck('id')->all());
});
