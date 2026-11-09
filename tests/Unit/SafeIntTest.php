<?php

declare(strict_types=1);

use App\Shared\Support\SafeInt;
use App\Shared\Support\ValueTooLargeException;

// Revue I-3 : multiplications et additions entières sans passage silencieux en flottant.

test('produits et sommes exacts jusqu\'à la limite des entiers 64 bits', function (): void {
    expect(SafeInt::mul(0, PHP_INT_MAX))->toBe(0)
        ->and(SafeInt::mul(-3, 7))->toBe(-21)
        ->and(SafeInt::mul(3_037_000_499, 3_037_000_499))->toBe(9_223_372_030_926_249_001)
        ->and(SafeInt::mul(PHP_INT_MAX, 1))->toBe(PHP_INT_MAX)
        ->and(SafeInt::add(PHP_INT_MAX - 1, 1))->toBe(PHP_INT_MAX)
        ->and(SafeInt::add(PHP_INT_MIN + 1, -1))->toBe(PHP_INT_MIN);
});

test('dépassement : exception métier au lieu d\'un flottant', function (Closure $operation): void {
    expect($operation)->toThrow(ValueTooLargeException::class, 'Valeurs trop grandes');
})->with([
    'produit' => fn () => SafeInt::mul(3_037_000_500, 3_037_000_500),
    'produit négatif' => fn () => SafeInt::mul(-4_000_000_000, 4_000_000_000),
    'somme' => fn () => SafeInt::add(PHP_INT_MAX, 1),
    'somme négative' => fn () => SafeInt::add(PHP_INT_MIN, -1),
]);
