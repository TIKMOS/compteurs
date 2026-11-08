<?php

declare(strict_types=1);

use App\Modules\Metering\Data\OccupancySpan;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Services\CreditSettlementCalculator;
use App\Modules\Metering\Services\UtilityAllocator;
use App\Modules\Metering\Tests\Support\Fixtures as F;
use App\Shared\Enums\ReadingKind;

// Exemple chiffré complet de CONCEPTION.md § 5, de bout en bout (tranches → crédit → répartition)

/** @return array{11: OccupancySpan, 12: OccupancySpan, 13: OccupancySpan, 14: OccupancySpan} */
function occupants(): array
{
    return [
        11 => F::occupancy(11, '2025-01-01'),                 // A. Houngbédji, Chambre 1
        12 => F::occupancy(12, '2025-03-01', '2026-11-15'),   // B. Dossou, Chambre 2
        13 => F::occupancy(13, '2025-06-01'),                 // C. Agossou, Chambre 3
        14 => F::occupancy(14, '2026-11-20'),                 // D. Kpadonou, Chambre 2
    ];
}

test('§ 5.2 octobre : électricité et eau', function (): void {
    $o = occupants();
    $electricity = (new UtilityAllocator)->allocate(62055, F::house([
        1 => ['Chambre 1', [$o[11]], [F::monthEnd('2026-09-30', 1250), F::monthEnd('2026-10-31', 1392)]],
        2 => ['Chambre 2', [$o[12]], [F::monthEnd('2026-09-30', 3480), F::monthEnd('2026-10-31', 3578)]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-09-30', 702), F::monthEnd('2026-10-31', 917)]],
    ]));
    $water = (new UtilityAllocator)->allocate(18505, F::house([
        1 => ['Chambre 1', [$o[11]], [F::monthEnd('2026-09-30', 412), F::monthEnd('2026-10-31', 421)]],
        2 => ['Chambre 2', [$o[12]], [F::monthEnd('2026-09-30', 188), F::monthEnd('2026-10-31', 195)]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-09-30', 960), F::monthEnd('2026-10-31', 974)]],
    ]));

    expect([$electricity->amountFor(1, 11), $electricity->amountFor(2, 12), $electricity->amountFor(3, 13)])->toBe([19367, 13366, 29322]);
    expect([$water->amountFor(1, 11), $water->amountFor(2, 12), $water->amountFor(3, 13)])->toBe([5552, 4318, 8635]);
    expect($electricity->payers)->toHaveCount(3);
});

test('T-15 remplacement de décompteur : 50 + 92 = 142 kWh, résultat identique à octobre', function (): void {
    $o = occupants();
    $units = F::house([
        2 => ['Chambre 2', [$o[12]], [F::monthEnd('2026-09-30', 3480), F::monthEnd('2026-10-31', 3578)]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-09-30', 702), F::monthEnd('2026-10-31', 917)]],
    ]);
    array_unshift($units, [
        'unitId' => 1,
        'unitLabel' => 'Chambre 1',
        'occupancies' => [$o[11]],
        'subMeters' => [
            ['id' => 101, 'readings' => [
                F::monthEnd('2026-09-30', 1250),
                new Reading(ReadingKind::Removal, F::date('2026-10-12'), F::m(1300)),
            ]],
            ['id' => 102, 'readings' => [
                new Reading(ReadingKind::Installation, F::date('2026-10-12'), 0),
                F::monthEnd('2026-10-31', 92),
            ]],
        ],
    ]);

    $electricity = (new UtilityAllocator)->allocate(62055, $units);

    expect($electricity->payer(1, 11)->billed)->toBe(F::m(142));
    expect($electricity->amountFor(1, 11))->toBe(19367);
});

test('§ 5.3 novembre : sortie, vacance, entrée et relevé estimé', function (): void {
    $o = occupants();
    $electricity = (new UtilityAllocator)->allocate(58900, F::house([
        1 => ['Chambre 1', [$o[11]], [F::monthEnd('2026-10-31', 1392), F::monthEnd('2026-11-30', 1534, estimated: true)]],
        2 => ['Chambre 2', [$o[12], $o[14]], [
            F::monthEnd('2026-10-31', 3578), F::exit('2026-11-15', 3626, 12),
            F::entry('2026-11-20', 3631, 14), F::monthEnd('2026-11-30', 3680),
        ]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-10-31', 917), F::monthEnd('2026-11-30', 1118)]],
    ]));
    $water = (new UtilityAllocator)->allocate(17270, F::house([
        1 => ['Chambre 1', [$o[11]], [F::monthEnd('2026-10-31', 421), F::monthEnd('2026-11-30', 430, estimated: true)]],
        2 => ['Chambre 2', [$o[12], $o[14]], [
            F::monthEnd('2026-10-31', 195), F::exit('2026-11-15', 199, 12),
            F::entry('2026-11-20', '199.5', 14), F::monthEnd('2026-11-30', '202.5'),
        ]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-10-31', 974), F::monthEnd('2026-11-30', 987)]],
    ]));

    expect($electricity->result->unitPrice)->toBe('11780/89');
    expect([
        $electricity->amountFor(1, 11), $electricity->amountFor(2, 12), $electricity->amountFor(2, null),
        $electricity->amountFor(2, 14), $electricity->amountFor(3, 13),
    ])->toBe([18795, 6353, 662, 6486, 26604]);

    expect($water->result->unitPrice)->toBe('34540/59');
    expect([
        $water->amountFor(1, 11), $water->amountFor(2, 12), $water->amountFor(2, null),
        $water->amountFor(2, 14), $water->amountFor(3, 13),
    ])->toBe([5269, 2342, 293, 1756, 7610]);
    expect($water->result->lines[$water->payer(3, 13)->key]->roundingAdjustment)->toBe(-1);

    expect($electricity->payer(1, 11)->isEstimated)->toBeTrue();
    expect($electricity->payer(3, 13)->isEstimated)->toBeFalse();
    expect($electricity->payer(2, null)->isVacant())->toBeTrue();

    // Récapitulatif : propriétaire / vacant 955, total 76 170
    expect($electricity->amountFor(2, null) + $water->amountFor(2, null))->toBe(955);
    expect($electricity->result->sum() + $water->result->sum())->toBe(76170);
});

test('§ 5.4 décembre : régularisation positive de 137 kWh', function (): void {
    $o = occupants();
    $electricity = (new UtilityAllocator)->allocate(60000, F::house([
        1 => ['Chambre 1', [$o[11]], [F::monthEnd('2026-11-30', 1534, estimated: true), F::monthEnd('2026-12-31', 1671)]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-11-30', 1118), F::monthEnd('2026-12-31', 1318)]],
    ]));

    expect($electricity->payer(1, 11)->billed)->toBe(F::m(137));
    expect($electricity->payer(1, 11)->includesRegularization)->toBeTrue();
});

test('§ 5.5 / T-08 surestimation : 0 / 24 706 / 35 294 et crédit restant de 14 kWh', function (): void {
    $o = occupants();
    $electricity = (new UtilityAllocator)->allocate(60000, F::house([
        1 => ['Chambre 1', [$o[11]], [F::estimatedMonthEnd('2026-11-30', 1534, paidBy: 11, unitPrice: F::PU_NOV), F::monthEnd('2026-12-31', 1520)]],
        2 => ['Chambre 2', [$o[14]], [F::monthEnd('2026-11-30', 3680), F::monthEnd('2026-12-31', 3820)]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-11-30', 1118), F::monthEnd('2026-12-31', 1318)]],
    ]));

    expect($electricity->result->unitPrice)->toBe('3000/17');
    expect([$electricity->amountFor(1, 11), $electricity->amountFor(2, 14), $electricity->amountFor(3, 13)])->toBe([0, 24706, 35294]);
    expect($electricity->payer(1, 11)->raw)->toBe(F::m(-14));
    expect($electricity->credits[1]->balanceFor(11))->toBe(F::m(14));
});

test('§ 5.6 A : sortie de A. Houngbédji, crédit déduit de sa dernière fiche, répartition inchangée', function (): void {
    $o = [11 => F::occupancy(11, '2025-01-01', '2027-01-10'), 13 => F::occupancy(13, '2025-06-01'), 14 => F::occupancy(14, '2026-11-20')];
    $electricity = (new UtilityAllocator)->allocate(50000, F::house([
        1 => ['Chambre 1', [$o[11]], [F::monthEnd('2026-12-31', 1520), F::exit('2027-01-10', 1525, 11)], [F::lot(11, 14, F::PU_NOV)]],
        2 => ['Chambre 2', [$o[14]], [F::monthEnd('2026-12-31', 3820), F::monthEnd('2027-01-31', 3960)]],
        3 => ['Chambre 3', [$o[13]], [F::monthEnd('2026-12-31', 1318), F::monthEnd('2027-01-31', 1518)]],
    ]));

    expect($electricity->amountFor(1, 11))->toBe(0);
    expect($electricity->result->sum())->toBe(50000);

    $settled = $electricity->credits[1]->settlements[11];
    $statement = $electricity->amountFor(1, 11) + 1800; // + quote-part d'eau de janvier (exemple)
    $settlement = (new CreditSettlementCalculator)->settle($statement, [$settled->value]);

    expect([$settlement->creditValue, $settlement->netDue(), $settlement->refundDue])->toBe([1191, 609, 0]);
});
