<?php

declare(strict_types=1);

use App\Modules\Metering\Data\AllocationLine;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Data\ResolvedPrice;
use App\Modules\Metering\Exceptions\MissingBillException;
use App\Modules\Metering\Exceptions\ZeroConsumptionException;
use App\Modules\Metering\Services\AllocationCalculator;
use App\Modules\Metering\Services\UnitPriceResolver;
use App\Shared\Enums\PriceSource;
use App\Shared\Support\Fraction;

// Mode 2 (CONCEPTION.md § 5.8, § 5.7 T-36 à T-45) : choix du prix et répartition à prix fixé.
// Octobre électricité : facture 62 055, consommations 142 / 98 / 215 (Σ 455).

function octoberLines(): array
{
    return [
        new AllocationLine('c1', 'Chambre 1', 142_000, '2026-08-01'),
        new AllocationLine('c2', 'Chambre 2', 98_000, '2026-08-01'),
        new AllocationLine('c3', 'Chambre 3', 215_000, '2026-08-01'),
    ];
}

function amounts($result): array
{
    return array_map(fn ($l) => $l->amount, array_values($result->lines));
}

test('T-36 plafonné, prix fixé 127,50 < prix réel : prix fixé, 18 105 / 12 495 / 27 413', function (): void {
    $price = (new UnitPriceResolver)->resolve(PricingRule::fixed('127.50'), 62055, 455_000);

    expect($price->source)->toBe(PriceSource::Fixed)
        ->and((string) $price->applied)->toBe('255/2')
        ->and((string) $price->actual)->toBe('1773/13')
        ->and($price->label('kWh'))->toBe('Prix appliqué : 127,50 FCFA/kWh — prix fixé (prix réel : 136,385)');

    $result = (new AllocationCalculator)->allocateAtPrice($price->applied, octoberLines(), 62055);
    expect(amounts($result))->toBe([18105, 12495, 27413])
        ->and($result->sum())->toBe(58013)
        ->and(array_sum(array_map(fn ($l) => $l->roundingAdjustment, $result->lines)))->toBe(0);
});

test('T-37 plafonné, prix fixé 150 > prix réel : prix réel (D-23), libellé d\'EF-42', function (): void {
    $price = (new UnitPriceResolver)->resolve(PricingRule::fixed('150'), 62055, 455_000);

    expect($price->source)->toBe(PriceSource::Actual)
        ->and((string) $price->applied)->toBe('1773/13')
        ->and($price->label('kWh'))->toBe('Prix appliqué : 136,385 FCFA/kWh — prix réel, inférieur au prix fixé de 150,00');
});

test('T-38 prix fixé égal au prix réel : prix réel', function (): void {
    expect((new UnitPriceResolver)->resolve(PricingRule::fixed('125'), 1000, 8_000)->source)->toBe(PriceSource::Actual);
});

test('T-39 / T-40 non plafonné : prix fixé, avec ou sans facture', function (): void {
    $resolver = new UnitPriceResolver;

    $withBill = $resolver->resolve(PricingRule::fixed('150', false), 62055, 455_000);
    $withoutBill = $resolver->resolve(PricingRule::fixed('150', false), null, 455_000);

    expect($withBill->source)->toBe(PriceSource::Fixed)
        ->and((string) $withBill->actual)->toBe('1773/13')
        ->and($withoutBill->actual)->toBeNull()
        ->and($withoutBill->label('kWh'))->toBe('Prix appliqué : 150,00 FCFA/kWh — prix fixé, sans plafond')
        ->and(amounts((new AllocationCalculator)->allocateAtPrice($withoutBill->applied, octoberLines())))
        ->toBe([21300, 14700, 32250]);
});

test('T-41 facture absente : refus en mode 1 et en mode 2 plafonné', function (): void {
    $resolver = new UnitPriceResolver;

    expect(fn () => $resolver->resolve(PricingRule::billSplit(), null, 455_000))->toThrow(MissingBillException::class)
        ->and(fn () => $resolver->resolve(PricingRule::fixed('127.50'), null, 455_000))->toThrow(MissingBillException::class);
});

test('T-42 Σ consommations nulle : bloquée en mode 1 et plafonné, permise sans plafond', function (): void {
    $resolver = new UnitPriceResolver;
    $zeros = [new AllocationLine('a', 'A', 0), new AllocationLine('b', 'B', 0)];

    expect(fn () => $resolver->resolve(PricingRule::billSplit(), 3500, 0))->toThrow(ZeroConsumptionException::class)
        ->and(fn () => $resolver->resolve(PricingRule::fixed('600'), 3500, 0))->toThrow(ZeroConsumptionException::class);

    $price = $resolver->resolve(PricingRule::fixed('600', false), null, 0);
    expect(amounts((new AllocationCalculator)->allocateAtPrice($price->applied, $zeros)))->toBe([0, 0]);
});

test('T-45 H-31 excédent d\'arrondi retiré au premier libellé : 249 / 250 / 250 / 250', function (): void {
    $lines = array_map(fn (int $i) => new AllocationLine("c{$i}", "Chambre {$i}", 1_500, '2026-08-01'), [1, 2, 3, 4]);
    $price = (new UnitPriceResolver)->resolve(PricingRule::fixed('166.40'), 999, 6_000);

    expect($price->source)->toBe(PriceSource::Fixed);

    $result = (new AllocationCalculator)->allocateAtPrice($price->applied, $lines, 999);
    expect(amounts($result))->toBe([249, 250, 250, 250])
        ->and($result->sum())->toBe(999);
});

test('affichage d\'un prix : 3 décimales au plus, espace insécable', function (): void {
    expect(ResolvedPrice::format(new Fraction(11780, 89)))->toBe('132,36')
        ->and(ResolvedPrice::format(new Fraction(1250)))->toBe("1\u{00A0}250")
        ->and(ResolvedPrice::format(new Fraction(255, 2), 2))->toBe('127,50');
});
