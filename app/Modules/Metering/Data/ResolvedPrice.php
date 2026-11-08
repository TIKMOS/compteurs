<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Enums\PriceSource;
use App\Shared\Enums\PricingMode;
use App\Shared\Support\Fraction;
use App\Shared\Support\Milli;

/**
 * Prix d'un fluide pour un mois (EF-41 à EF-43) : prix réel (null sans facture ou si Σ = 0),
 * prix appliqué et son origine. Fractions exactes en FCFA par kWh ou par m³.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public PricingRule $rule,
        public ?Fraction $actual,
        public Fraction $applied,
        public PriceSource $source,
    ) {}

    /**
     * Arrondi d'affichage à 3 décimales (EF-27), sans zéros inutiles ; 2 décimales fixes pour un prix fixé.
     */
    public static function format(?Fraction $price, ?int $fixedDecimals = null): string
    {
        if ($price === null) {
            return '—';
        }

        $thousandths = Milli::roundHalfUp($price->numerator * 1000, $price->denominator);
        $decimals = sprintf('%03d', $thousandths % 1000);
        $decimals = $fixedDecimals === null ? rtrim($decimals, '0') : substr($decimals, 0, $fixedDecimals);

        return number_format(intdiv($thousandths, 1000), 0, ',', "\u{00A0}") . ($decimals === '' ? '' : ',' . $decimals);
    }

    /**
     * Libellé d'EF-42 : « Prix appliqué : 127,50 FCFA/kWh — prix fixé (prix réel : 136,385) ».
     */
    public function label(string $unit): string
    {
        // Un prix fixé s'affiche avec ses 2 décimales (« 127,50 »), un prix réel à 3 au plus (EF-27).
        $applied = self::format($this->applied, $this->source === PriceSource::Fixed ? 2 : null);

        if ($this->rule->mode === PricingMode::BillSplit) {
            return "Prix unitaire : {$applied} FCFA/{$unit} — répartition de la facture";
        }

        if ($this->source === PriceSource::Actual) {
            $fixed = self::format(Fraction::fromDecimal((string) $this->rule->fixedUnitPrice), 2);

            return "Prix appliqué : {$applied} FCFA/{$unit} — prix réel, inférieur au prix fixé de {$fixed}";
        }

        if (! $this->rule->capToActual) {
            return "Prix appliqué : {$applied} FCFA/{$unit} — prix fixé, sans plafond";
        }

        return "Prix appliqué : {$applied} FCFA/{$unit} — prix fixé (prix réel : " . self::format($this->actual) . ')';
    }
}
