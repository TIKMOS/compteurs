<?php

declare(strict_types=1);

use App\Modules\Metering\Exceptions\ZeroConsumptionException;
use App\Modules\Metering\Services\AllocationCalculator;
use App\Modules\Metering\Tests\Support\Fixtures as F;

// CONCEPTION.md § 5.2 et § 5.7

test('T-01 octobre électricité : prix unitaire 1773/13, écart d\'arrondi −1 sur la Chambre 3', function (): void {
    $result = (new AllocationCalculator)->allocate(62055, F::lines(['Chambre 1' => 142, 'Chambre 2' => 98, 'Chambre 3' => 215]));

    expect($result->unitPrice)->toBe('1773/13');
    expect($result->amountFor('Chambre 1'))->toBe(19367);
    expect($result->amountFor('Chambre 2'))->toBe(13366);
    expect($result->amountFor('Chambre 3'))->toBe(29322);
    expect($result->lines['Chambre 3']->roundingAdjustment)->toBe(-1);
    expect($result->lines['Chambre 3']->theoreticalAmount)->toBe('381195/13');
    expect($result->sum())->toBe(62055);
});

test('T-02 octobre eau : 5 552 / 4 318 / 8 635', function (): void {
    $result = (new AllocationCalculator)->allocate(18505, F::lines(['Chambre 1' => 9, 'Chambre 2' => 7, 'Chambre 3' => 14]));

    expect($result->unitPrice)->toBe('3701/6');
    expect([$result->amountFor('Chambre 1'), $result->amountFor('Chambre 2'), $result->amountFor('Chambre 3')])
        ->toBe([5552, 4318, 8635]);
    expect($result->sum())->toBe(18505);
});

test('T-13 somme des consommations nulle : la répartition est refusée', function (): void {
    expect(fn () => (new AllocationCalculator)->allocate(3500, F::lines(['Chambre 1' => 0, 'Chambre 2' => 0, 'Chambre 3' => 0])))
        ->toThrow(ZeroConsumptionException::class, "3\u{00A0}500 FCFA ne peut pas être répartie");
});

test('T-14 égalité de plus gros consommateur : ajustement sur le premier libellé', function (): void {
    $result = (new AllocationCalculator)->allocate(1000, F::lines(['Chambre 2' => 3, 'Chambre 1' => 3, 'Chambre 3' => 1]));

    expect($result->amountFor('Chambre 1'))->toBe(428);
    expect($result->amountFor('Chambre 2'))->toBe(429);
    expect($result->amountFor('Chambre 3'))->toBe(143);
    expect($result->sum())->toBe(1000);
});

test('H-5 ordre naturel des libellés : « Chambre 2 » avant « Chambre 10 »', function (): void {
    $result = (new AllocationCalculator)->allocate(1000, F::lines(['Chambre 10' => 3, 'Chambre 2' => 3, 'Chambre 3' => 1]));

    expect($result->lines['Chambre 2']->roundingAdjustment)->toBe(-1);
    expect($result->lines['Chambre 10']->roundingAdjustment)->toBe(0);
});

test('T-28 facture minuscule : l\'écart négatif déborde, aucun montant négatif', function (): void {
    $result = (new AllocationCalculator)->allocate(2, F::lines(['Chambre 1' => 1, 'Chambre 2' => 1, 'Chambre 3' => 1, 'Chambre 4' => 1]));

    expect([$result->amountFor('Chambre 1'), $result->amountFor('Chambre 2'), $result->amountFor('Chambre 3'), $result->amountFor('Chambre 4')])
        ->toBe([0, 0, 1, 1]);
    expect($result->sum())->toBe(2);
});

test('T-19 invariant : la somme des quotes-parts égale la facture, sans montant négatif', function (): void {
    $calculator = new AllocationCalculator;
    mt_srand(20261107);

    for ($run = 0; $run < 500; $run++) {
        $total = mt_rand(0, 250000);
        $consumptions = [];
        for ($i = 1, $n = mt_rand(1, 8); $i <= $n; $i++) {
            $consumptions["Logement {$i}"] = mt_rand(0, 400000) / 1000;
        }
        $consumptions['Logement 1'] = max(0.001, $consumptions['Logement 1']);

        $result = $calculator->allocate($total, F::lines(array_map(static fn (float $c): string => number_format($c, 3, '.', ''), $consumptions)));

        expect($result->sum())->toBe($total);
        foreach ($result->lines as $line) {
            expect($line->amount >= 0)->toBeTrue();
        }
    }
});
