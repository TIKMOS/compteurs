<?php

declare(strict_types=1);

use App\Modules\Metering\Services\CreditSettlementCalculator;
use App\Shared\Support\Fraction;

// Solde du crédit sur la dernière fiche (EF-24 f), CONCEPTION.md § 5.6

test('T-29 crédit inférieur à la fiche : 1 800 − 1 191 = 609 à payer', function (): void {
    $settlement = (new CreditSettlementCalculator)->settle(1800, [Fraction::parse('106020/89')]);

    expect([$settlement->creditValue, $settlement->deductedAmount, $settlement->netDue(), $settlement->refundDue])
        ->toBe([1191, 1191, 609, 0]);
});

test('T-30 crédit supérieur à la fiche : fiche à 0 et 14 948 à rembourser', function (): void {
    $settlement = (new CreditSettlementCalculator)->settle(1200, [Fraction::parse('1437160/89')]);

    expect([$settlement->creditValue, $settlement->deductedAmount, $settlement->netDue(), $settlement->refundDue])
        ->toBe([16148, 1200, 0, 14948]);
});

test('T-32 arrondi unique sur la somme électricité + eau', function (): void {
    $settlement = (new CreditSettlementCalculator)->settle(50000, [Fraction::parse('106020/89'), Fraction::parse('17270/59')]);

    expect($settlement->creditValue)->toBe(1484);
});

test('T-35 fiche à 0 : tout le crédit est à rembourser', function (): void {
    $settlement = (new CreditSettlementCalculator)->settle(0, [Fraction::parse('500')]);

    expect([$settlement->deductedAmount, $settlement->refundDue])->toBe([0, 500]);
});

test('crédit égal à la fiche : net 0, rien à rembourser', function (): void {
    $settlement = (new CreditSettlementCalculator)->settle(1191, [Fraction::parse('106020/89')]);

    expect([$settlement->netDue(), $settlement->refundDue])->toBe([0, 0]);
});
