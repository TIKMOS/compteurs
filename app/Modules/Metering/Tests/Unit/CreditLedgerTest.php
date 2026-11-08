<?php

declare(strict_types=1);

use App\Modules\Metering\Data\CreditLot;
use App\Modules\Metering\Data\CreditResult;
use App\Modules\Metering\Data\Reading;
use App\Modules\Metering\Services\CreditLedger;
use App\Modules\Metering\Services\ReadingEstimator;
use App\Modules\Metering\Services\ReadingValidator;
use App\Modules\Metering\Services\SegmentBuilder;
use App\Modules\Metering\Tests\Support\Fixtures as F;
use App\Shared\Enums\CreditMovementKind;
use App\Shared\Enums\ReadingKind;

// Crédit de consommation du locataire (EF-24), CONCEPTION.md § 5.5, § 5.6 et § 5.7

const HOUNGBEDJI = 11;

/**
 * Un mois de la Chambre 1, occupée par A. Houngbédji.
 *
 * @param list<Reading> $readings
 * @param list<CreditLot> $lots
 * @param list<int> $exiting
 */
function chambre1(array $readings, array $lots = [], array $exiting = [], ?string $endedOn = null): CreditResult
{
    return (new CreditLedger)->apply(
        (new SegmentBuilder)->build($readings, [F::occupancy(HOUNGBEDJI, '2025-01-01', $endedOn)]),
        F::lotsByPayer($lots),
        $exiting,
    );
}

test('T-08 surestimation : consommation ramenée à 0, lot de 14 kWh au prix de novembre pour le payeur estimé', function (): void {
    $december = chambre1([
        F::estimatedMonthEnd('2026-11-30', 1534, paidBy: HOUNGBEDJI, unitPrice: F::PU_NOV),
        F::monthEnd('2026-12-31', 1520),
    ]);

    expect($december->segments[0]->billed)->toBe(0);
    expect($december->created())->toBe(F::m(14));
    expect($december->createdLots[0]->occupancyId)->toBe(HOUNGBEDJI);
    expect((string) $december->createdLots[0]->unitPrice)->toBe(F::PU_NOV);
    expect($december->createdLots[0]->originMonth)->toBe('2026-11');
    expect($december->balanceFor(HOUNGBEDJI))->toBe(F::m(14));
});

test('T-20 crédit absorbé en un mois : 150 − 14 = 136 facturés', function (): void {
    $january = chambre1(
        [F::monthEnd('2026-12-31', 1520), F::monthEnd('2027-01-31', 1670)],
        [F::lot(HOUNGBEDJI, 14, F::PU_NOV)],
    );

    expect($january->segments[0]->creditApplied)->toBe(F::m(14));
    expect($january->segments[0]->billed)->toBe(F::m(136));
    expect($january->balanceFor(HOUNGBEDJI))->toBe(0);
    expect($january->closingLots)->toBe([]);
});

test('T-21 crédit étalé sur plusieurs mois sans consommation négative', function (): void {
    $december = chambre1([
        F::estimatedMonthEnd('2026-11-30', 1534, paidBy: HOUNGBEDJI, unitPrice: F::PU_NOV),
        F::monthEnd('2026-12-31', 1392),
    ]);
    $january = chambre1([F::monthEnd('2026-12-31', 1392), F::monthEnd('2027-01-31', 1452)], $december->closingLots['occ:11']);
    $february = chambre1([F::monthEnd('2027-01-31', 1452), F::monthEnd('2027-02-28', 1522)], $january->closingLots['occ:11']);
    $march = chambre1([F::monthEnd('2027-02-28', 1522), F::monthEnd('2027-03-31', 1622)], $february->closingLots['occ:11']);

    expect($december->balanceFor(HOUNGBEDJI))->toBe(F::m(142));
    expect([$january->segments[0]->billed, $january->balanceFor(HOUNGBEDJI)])->toBe([0, F::m(82)]);
    expect([$february->segments[0]->billed, $february->balanceFor(HOUNGBEDJI)])->toBe([0, F::m(12)]);
    expect([$march->segments[0]->billed, $march->balanceFor(HOUNGBEDJI)])->toBe([F::m(88), 0]);
});

test('T-22 crédit suivi d\'une nouvelle estimation, puis d\'un nouveau crédit', function (): void {
    $december = chambre1([
        F::estimatedMonthEnd('2026-11-30', 1534, paidBy: HOUNGBEDJI, unitPrice: F::PU_NOV),
        F::monthEnd('2026-12-31', 1520),
    ]);

    // Janvier : relevé manquant, historique facturé 142, 142, 0
    $estimated = (new ReadingEstimator)->estimate(F::m(1520), F::date('2026-12-31'), F::date('2027-01-31'), [F::m(142), F::m(142), 0]);
    expect($estimated)->toBe(F::m('1614.667'));

    $january = chambre1([F::monthEnd('2026-12-31', 1520), F::monthEnd('2027-01-31', '1614.667', estimated: true)], $december->closingLots['occ:11']);
    expect([$january->segments[0]->creditApplied, $january->segments[0]->billed, $january->balanceFor(HOUNGBEDJI)])
        ->toBe([F::m(14), F::m('80.667'), 0]);

    // Février : réel 1 600, inférieur à l'estimé mais pas au dernier réel (1 520) ; le lot prend le PU de janvier
    $januaryEstimate = F::estimatedMonthEnd('2027-01-31', '1614.667', paidBy: HOUNGBEDJI, unitPrice: '2000/11');
    (new ReadingValidator)->assertAcceptable($januaryEstimate, F::m(1600), F::m(1520));
    $february = chambre1([$januaryEstimate, F::monthEnd('2027-02-28', 1600)]);
    expect([$february->segments[0]->billed, $february->balanceFor(HOUNGBEDJI)])->toBe([0, F::m('14.667')]);
    expect($february->createdLots[0]->originMonth)->toBe('2027-01');

    // Mars : 150 bruts − 14,667 de crédit
    $march = chambre1([F::monthEnd('2027-02-28', 1600), F::monthEnd('2027-03-31', 1750)], $february->closingLots['occ:11']);
    expect([$march->segments[0]->billed, $march->balanceFor(HOUNGBEDJI)])->toBe([F::m('135.333'), 0]);

    $billed = F::m(142) + $december->segments[0]->billed + $january->segments[0]->billed
        + $february->segments[0]->billed + $march->segments[0]->billed;
    expect($billed)->toBe(F::m(1750 - 1392));
});

test('T-23 le crédit ne passe pas au locataire suivant ; le reste est soldé à la sortie', function (): void {
    $result = (new CreditLedger)->apply(
        (new SegmentBuilder)->build(
            [F::monthEnd('2026-10-31', 3578), F::exit('2026-11-15', 3626, 12), F::entry('2026-11-20', 3631, 14), F::monthEnd('2026-11-30', 3680)],
            [F::occupancy(12, '2025-03-01', '2026-11-15'), F::occupancy(14, '2026-11-20')],
        ),
        F::lotsByPayer([F::lot(12, 60, F::PU_OCT, '2026-10')]),
        [12],
    );

    expect(array_map(fn ($s) => $s->billed, $result->segments))->toBe([0, F::m(5), F::m(49)]);
    expect($result->segments[2]->creditApplied)->toBe(0);
    expect($result->settlements[12]->quantity)->toBe(F::m(12));
    expect((string) $result->settlements[12]->value)->toBe('21276/13');
    expect($result->settlements[12]->value->roundHalfUp())->toBe(1637);
    expect($result->balanceFor(12))->toBe(0);
    expect($result->balanceFor(14))->toBe(0);
});

test('T-24 remplacement de décompteur : le crédit du locataire s\'impute sur le nouveau, sans transfert', function (): void {
    $builder = new SegmentBuilder;
    $occupancies = [F::occupancy(HOUNGBEDJI, '2025-01-01')];
    $segments = [
        ...$builder->build([F::monthEnd('2026-11-30', 1392), new Reading(ReadingKind::Removal, F::date('2026-12-10'), F::m(1400))], $occupancies),
        ...$builder->build([new Reading(ReadingKind::Installation, F::date('2026-12-10'), 0), F::monthEnd('2026-12-31', 30)], $occupancies),
    ];

    $result = (new CreditLedger)->apply($segments, F::lotsByPayer([F::lot(HOUNGBEDJI, 20, F::PU_NOV, id: 7)]));

    expect(array_map(fn ($s) => [$s->creditApplied, $s->billed], $result->segments))
        ->toBe([[F::m(8), 0], [F::m(12), F::m(18)]]);
    expect($result->balanceFor(HOUNGBEDJI))->toBe(0);
    expect(array_map(fn ($m) => [$m->kind, $m->lot->reference(), $m->quantity], $result->movements))
        ->toBe([[CreditMovementKind::Applied, 'db:7', F::m(8)], [CreditMovementKind::Applied, 'db:7', F::m(12)]]);
});

test('T-29 sortie avec crédit restant de 9 kWh : 1 191,236 FCFA', function (): void {
    $january = chambre1(
        [F::monthEnd('2026-12-31', 1520), F::exit('2027-01-10', 1525, HOUNGBEDJI)],
        [F::lot(HOUNGBEDJI, 14, F::PU_NOV)],
        exiting: [HOUNGBEDJI],
        endedOn: '2027-01-10',
    );

    expect([$january->segments[0]->creditApplied, $january->segments[0]->billed])->toBe([F::m(5), 0]);
    expect($january->settlements[HOUNGBEDJI]->quantity)->toBe(F::m(9));
    expect((string) $january->settlements[HOUNGBEDJI]->value)->toBe('106020/89');
    expect($january->closingLots)->toBe([]);
    expect(array_map(fn ($m) => [$m->kind, $m->quantity], $january->movements))
        ->toBe([[CreditMovementKind::Applied, F::m(5)], [CreditMovementKind::Settled, F::m(9)]]);
});

test('T-30 sortie avec crédit restant de 122 kWh : 16 147,865 FCFA', function (): void {
    $january = chambre1(
        [F::monthEnd('2026-12-31', 1392), F::exit('2027-01-10', 1412, HOUNGBEDJI)],
        [F::lot(HOUNGBEDJI, 142, F::PU_NOV)],
        exiting: [HOUNGBEDJI],
        endedOn: '2027-01-10',
    );

    expect($january->segments[0]->billed)->toBe(0);
    expect($january->settlements[HOUNGBEDJI]->value->roundHalfUp())->toBe(16148);
});

test('T-31 lots à prix différents : imputation du plus ancien, valorisation lot par lot', function (): void {
    $january = chambre1(
        [F::monthEnd('2026-12-31', 1520), F::exit('2027-01-10', 1525, HOUNGBEDJI)],
        [F::lot(HOUNGBEDJI, 14, F::PU_NOV, '2026-11'), F::lot(HOUNGBEDJI, 10, F::PU_DEC, '2026-12')],
        exiting: [HOUNGBEDJI],
        endedOn: '2027-01-10',
    );

    expect($january->settlements[HOUNGBEDJI]->quantity)->toBe(F::m(19));
    expect((string) $january->settlements[HOUNGBEDJI]->value)->toBe('4472340/1513');
    expect($january->settlements[HOUNGBEDJI]->value->roundHalfUp())->toBe(2956);
});

test('T-33 sortie sans crédit : aucun solde', function (): void {
    $january = chambre1([F::monthEnd('2026-12-31', 1520), F::exit('2027-01-10', 1525, HOUNGBEDJI)], [], [HOUNGBEDJI], '2027-01-10');

    expect($january->settlements)->toBe([]);
});

test('T-34 crédit du propriétaire : imputé seulement sur les tranches vacantes', function (): void {
    $december = (new CreditLedger)->apply(
        (new SegmentBuilder)->build(
            [F::estimatedMonthEnd('2026-11-30', 200, paidBy: null, unitPrice: F::PU_NOV_WATER), F::monthEnd('2026-12-31', 197)],
            [],
        ),
        [],
    );
    expect($december->balanceFor(null))->toBe(F::m(3));

    $january = (new CreditLedger)->apply(
        (new SegmentBuilder)->build(
            [F::monthEnd('2026-12-31', 197), F::entry('2027-01-15', 199, 20), F::monthEnd('2027-01-31', 203)],
            [F::occupancy(20, '2027-01-15')],
        ),
        $december->closingLots,
    );

    expect(array_map(fn ($s) => [$s->segment->occupancyId, $s->billed], $january->segments))->toBe([[null, 0], [20, F::m(4)]]);
    expect($january->balanceFor(null))->toBe(F::m(1));
    expect($january->balanceFor(20))->toBe(0);
});

test('le crédit créé dans le mois s\'impute sur les tranches suivantes du même payeur seulement', function (): void {
    $result = (new CreditLedger)->apply(
        (new SegmentBuilder)->build(
            [
                F::estimatedMonthEnd('2026-11-30', 1534, paidBy: HOUNGBEDJI, unitPrice: F::PU_NOV),
                F::exit('2026-12-10', 1520, HOUNGBEDJI),
                F::entry('2026-12-10', 1520, 15),
                F::monthEnd('2026-12-31', 1560),
            ],
            [F::occupancy(HOUNGBEDJI, '2025-01-01', '2026-12-10'), F::occupancy(15, '2026-12-10')],
        ),
        [],
        [HOUNGBEDJI],
    );

    // L'entrant paie ses 40 kWh ; le sortant récupère ses 14 kWh en FCFA au prix de novembre
    expect(array_map(fn ($s) => $s->billed, $result->segments))->toBe([0, 0, F::m(40)]);
    expect((string) $result->settlements[HOUNGBEDJI]->value)->toBe('164920/89');
});
