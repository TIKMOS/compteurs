<?php

declare(strict_types=1);

namespace App\Shared\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Fraction exacte d'entiers, toujours irréductible, dénominateur positif (ENF-07 : aucun flottant).
 * Sérialisée en « a/b » (ou « a ») dans les colonnes VARCHAR unit_price, theoretical_amount, value.
 */
final readonly class Fraction implements Stringable
{
    public int $numerator;

    public int $denominator;

    public function __construct(int $numerator, int $denominator = 1)
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Dénominateur nul.');
        }
        if ($denominator < 0) {
            [$numerator, $denominator] = [-$numerator, -$denominator];
        }

        $gcd = self::gcd(abs($numerator), $denominator);
        $this->numerator = intdiv($numerator, $gcd);
        $this->denominator = intdiv($denominator, $gcd);
    }

    public function __toString(): string
    {
        return $this->denominator === 1 ? (string) $this->numerator : "{$this->numerator}/{$this->denominator}";
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function parse(string $value): self
    {
        if (preg_match('#^(-?\d+)(?:/(\d+))?$#', $value, $m) !== 1) {
            throw new InvalidArgumentException("Fraction invalide : « {$value} ».");
        }

        return new self((int) $m[1], isset($m[2]) ? (int) $m[2] : 1);
    }

    /** Décimal « 127.50 » (2 décimales au plus) → fraction exacte « 255/2 ». */
    public static function fromDecimal(string $value): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("Décimal invalide : « {$value} ».");
        }
        $decimals = $m[2] ?? '';

        return new self((int) ($m[1] . $decimals), 10 ** strlen($decimals));
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }

    public function add(self $other): self
    {
        $gcd = self::gcd($this->denominator, $other->denominator);
        $lcm = intdiv($this->denominator, $gcd) * $other->denominator;

        return new self(
            $this->numerator * intdiv($lcm, $this->denominator) + $other->numerator * intdiv($lcm, $other->denominator),
            $lcm,
        );
    }

    public function multiply(int $factor): self
    {
        $gcd = self::gcd(abs($factor), $this->denominator);

        return new self($this->numerator * intdiv($factor, $gcd), intdiv($this->denominator, $gcd));
    }

    public function divide(int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Division par zéro.');
        }
        $gcd = self::gcd(abs($this->numerator), abs($divisor));

        return new self(intdiv($this->numerator, $gcd), $this->denominator * intdiv($divisor, $gcd));
    }

    /** Comparaison exacte : −1, 0 ou 1 (dénominateurs positifs). */
    public function compareTo(self $other): int
    {
        return ($this->numerator * $other->denominator) <=> ($other->numerator * $this->denominator);
    }

    /** Arrondi au plus proche, 0,5 vers le haut ; réservé aux valeurs positives ou nulles. */
    public function roundHalfUp(): int
    {
        return Milli::roundHalfUp($this->numerator, $this->denominator);
    }
}
