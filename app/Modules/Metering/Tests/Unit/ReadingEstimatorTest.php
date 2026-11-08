<?php

declare(strict_types=1);

use App\Modules\Metering\Exceptions\EstimationImpossibleException;
use App\Modules\Metering\Exceptions\IndexLowerThanPreviousException;
use App\Modules\Metering\Services\ReadingEstimator;
use App\Modules\Metering\Services\ReadingValidator;
use App\Modules\Metering\Tests\Support\Fixtures as F;

// Estimation (EF-22, EF-25) et contrôle des index (EF-17), CONCEPTION.md § 5.3 et § 5.7

test('mois plein : moyenne des 3 derniers mois (Chambre 1, novembre)', function (): void {
    $estimator = new ReadingEstimator;
    $history = [F::m(150), F::m(134), F::m(142)];

    expect($estimator->estimate(F::m(1392), F::date('2026-10-31'), F::date('2026-11-30'), $history))->toBe(F::m(1534));
    expect($estimator->basis($history))->toBe('moyenne de 150, 134, 142');
});

test('seuls les 3 derniers mois comptent', function (): void {
    expect((new ReadingEstimator)->estimatedConsumption(F::date('2026-10-31'), F::date('2026-11-30'), [F::m(900), F::m(8), F::m(10), F::m(9)]))
        ->toBe(F::m(9));
});

test('T-10 entrée en cours de mois : moyenne ramenée aux jours restants', function (): void {
    expect((new ReadingEstimator)->estimate(F::m(3631), F::date('2026-11-20'), F::date('2026-11-30'), [F::m(90), F::m(102), F::m(96)]))
        ->toBe(F::m(3663));
});

test('T-11 historique court : moyenne des mois disponibles', function (): void {
    expect((new ReadingEstimator)->estimatedConsumption(F::date('2026-10-31'), F::date('2026-11-30'), [F::m(120), F::m(130)]))
        ->toBe(F::m(125));
});

test('T-12 aucun historique : estimation impossible', function (): void {
    expect(fn () => (new ReadingEstimator)->estimate(F::m(100), F::date('2026-10-31'), F::date('2026-11-30'), []))
        ->toThrow(EstimationImpossibleException::class, 'saisissez l\'index réel');
});

test('arrondi au millième : 284 ÷ 3 = 94,667', function (): void {
    expect((new ReadingEstimator)->estimatedConsumption(F::date('2026-12-31'), F::date('2027-01-31'), [F::m(142), F::m(142), 0]))
        ->toBe(F::m('94.667'));
});

test('T-25 index réel inférieur au dernier index réel : refus', function (): void {
    expect(fn () => (new ReadingValidator)->assertAcceptable(F::monthEnd('2026-11-30', 1534, estimated: true), F::m(1380), F::m(1392)))
        ->toThrow(IndexLowerThanPreviousException::class, "inférieur au dernier index réel (1\u{00A0}392)");
});

test('T-09 index inférieur au précédent réel : refus', function (): void {
    expect(fn () => (new ReadingValidator)->assertAcceptable(F::monthEnd('2026-09-30', 3480), F::m(3470), F::m(3480)))
        ->toThrow(IndexLowerThanPreviousException::class, "L'index saisi (3\u{00A0}470) est inférieur à l'index précédent (3\u{00A0}480).");
});

test('index réel entre le dernier réel et l\'estimé : accepté', function (): void {
    (new ReadingValidator)->assertAcceptable(F::monthEnd('2026-11-30', 1534, estimated: true), F::m(1520), F::m(1392));

    expect(true)->toBeTrue();
});
