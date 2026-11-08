<?php

declare(strict_types=1);

use App\Modules\Metering\Exceptions\IndexLowerThanPreviousException;
use App\Modules\Metering\Services\SegmentBuilder;
use App\Modules\Metering\Tests\Support\Fixtures as F;

// Attribution des tranches (EF-11), CONCEPTION.md § 5.3 et § 5.7

test('T-04 sortie, vacance puis entrée : 48 au sortant, 5 au propriétaire, 49 à l\'entrant', function (): void {
    $segments = (new SegmentBuilder)->build(
        [
            F::monthEnd('2026-11-30', 3680),
            F::entry('2026-11-20', 3631, 14),
            F::monthEnd('2026-10-31', 3578),
            F::exit('2026-11-15', 3626, 12),
        ],
        [F::occupancy(12, '2025-03-01', '2026-11-15'), F::occupancy(14, '2026-11-20')],
    );

    expect(array_map(fn ($s) => [$s->occupancyId, $s->rawConsumption()], $segments))
        ->toBe([[12, F::m(48)], [null, F::m(5)], [14, F::m(49)]]);
});

test('T-05 passation le même jour : tranche vacante nulle', function (): void {
    $segments = (new SegmentBuilder)->build(
        [
            F::monthEnd('2026-10-31', 3578),
            F::entry('2026-11-15', 3626, 14),
            F::exit('2026-11-15', 3626, 12),
            F::monthEnd('2026-11-30', 3680),
        ],
        [F::occupancy(12, '2025-03-01', '2026-11-15'), F::occupancy(14, '2026-11-15')],
    );

    expect(array_map(fn ($s) => [$s->occupancyId, $s->rawConsumption()], $segments))
        ->toBe([[12, F::m(48)], [null, 0], [14, F::m(54)]]);
});

test('T-06 logement vacant tout le mois : tranche au propriétaire', function (): void {
    $segments = (new SegmentBuilder)->build(
        [F::monthEnd('2026-10-31', 3578), F::monthEnd('2026-11-30', 3590)],
        [F::occupancy(12, '2025-03-01', '2026-10-31')],
    );

    expect($segments)->toHaveCount(1);
    expect($segments[0]->occupancyId)->toBeNull();
    expect($segments[0]->rawConsumption())->toBe(F::m(12));
});

test('T-27 logement vide puis entrée : la tranche avant l\'entrée est vacante', function (): void {
    $segments = (new SegmentBuilder)->build(
        [F::monthEnd('2026-10-31', 3578), F::entry('2026-11-20', 3590, 14), F::monthEnd('2026-11-30', 3640)],
        [F::occupancy(14, '2026-11-20')],
    );

    expect(array_map(fn ($s) => [$s->occupancyId, $s->rawConsumption()], $segments))
        ->toBe([[null, F::m(12)], [14, F::m(50)]]);
});

test('occupant en place tout le mois', function (): void {
    $segments = (new SegmentBuilder)->build(
        [F::monthEnd('2026-10-31', 1250), F::monthEnd('2026-11-30', 1392)],
        [F::occupancy(11, '2025-01-01')],
    );

    expect($segments[0]->occupancyId)->toBe(11);
});

test('T-09 index en baisse sans estimation : refus', function (): void {
    expect(fn () => (new SegmentBuilder)->build([F::monthEnd('2026-09-30', 3480), F::monthEnd('2026-10-31', 3470)], []))
        ->toThrow(IndexLowerThanPreviousException::class, 'inférieur à l\'index précédent');
});

test('index en baisse après une estimation : tranche négative admise et marquée régularisation', function (): void {
    $segments = (new SegmentBuilder)->build(
        [F::monthEnd('2026-11-30', 1534, estimated: true), F::monthEnd('2026-12-31', 1520)],
        [F::occupancy(11, '2025-01-01')],
    );

    expect($segments[0]->rawConsumption())->toBe(F::m(-14));
    expect($segments[0]->isRegularization())->toBeTrue();
    expect($segments[0]->isEstimated())->toBeFalse();
});
