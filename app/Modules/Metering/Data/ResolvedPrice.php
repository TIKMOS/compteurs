<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Models\UtilityBill;
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
     * Prix figé à la clôture (colonnes de utility_bills), ou null si le mois n'est pas figé.
     */
    public static function fromBill(UtilityBill $bill): ?self
    {
        if ($bill->unit_price === null || $bill->pricing_mode === null || $bill->price_source === null) {
            return null;
        }

        return new self(
            new PricingRule(PricingMode::from($bill->pricing_mode), $bill->fixed_unit_price, (bool) $bill->cap_to_actual),
            $bill->actual_unit_price === null ? null : Fraction::parse($bill->actual_unit_price),
            Fraction::parse($bill->unit_price),
            PriceSource::from($bill->price_source),
        );
    }

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
