<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Enums\AcknowledgementKind;
use App\Shared\Enums\PricingMode;
use App\Shared\Support\FrenchDecimal;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Règle de tarification d'un fluide : mode, prix fixé (mode 2) et plafond au prix réel
 * (EF-39 à EF-41). Le prix fixé est un décimal à 2 chiffres au plus, « 127.50 » (H-37).
 */
final readonly class PricingRule
{
    public ?string $fixedUnitPrice;

    public bool $capToActual;

    public function __construct(
        public PricingMode $mode,
        ?string $fixedUnitPrice = null,
        bool $capToActual = true,
    ) {
        if ($mode === PricingMode::FixedPrice) {
            $normalized = FrenchDecimal::parse($fixedUnitPrice);

            // DECIMAL(10,2) : 8 chiffres avant la virgule au plus ; strictement positif.
            if ($normalized === null || $normalized === '0.00' || strlen($normalized) > 11) {
                throw new InvalidArgumentException("Prix fixé invalide : « {$fixedUnitPrice} ».");
            }

            $this->fixedUnitPrice = $normalized;
            $this->capToActual = $capToActual;
        } else {
            // En mode 1, le prix fixé et le plafond n'ont pas de sens : valeurs neutres.
            $this->fixedUnitPrice = null;
            $this->capToActual = true;
        }
    }

    public static function billSplit(): self
    {
        return new self(PricingMode::BillSplit);
    }

    public static function fixed(string $price, bool $capToActual = true): self
    {
        return new self(PricingMode::FixedPrice, $price, $capToActual);
    }

    /**
     * La facture du mois est obligatoire en mode 1 et en mode 2 plafonné (EF-19, EF-41, EF-43).
     */
    public function requiresBill(): bool
    {
        return $this->mode === PricingMode::BillSplit || $this->capToActual;
    }

    public function equals(self $other): bool
    {
        return $this->mode === $other->mode
            && $this->fixedUnitPrice === $other->fixedUnitPrice
            && $this->capToActual === $other->capToActual;
    }

    /**
     * Confirmations exigées en passant de $previous à cette règle (EF-48, H-35) :
     * activation du mode 2, désactivation du plafond. Une maison neuve part du mode 1.
     *
     * @return list<AcknowledgementKind>
     */
    public function acknowledgementsRequiredFrom(?self $previous): array
    {
        if ($this->mode !== PricingMode::FixedPrice) {
            return [];
        }

        $previousWasFixed = $previous?->mode === PricingMode::FixedPrice;
        $kinds = [];

        if (! $previousWasFixed) {
            $kinds[] = AcknowledgementKind::FixedPriceEnabled;
        }

        if (! $this->capToActual && (! $previousWasFixed || $previous->capToActual)) {
            $kinds[] = AcknowledgementKind::CapDisabled;
        }

        return $kinds;
    }

    /**
     * Résumé en français : « Prix fixé : 127,50 FCFA/kWh, plafonné au prix réel ».
     */
    public function summary(string $unit): string
    {
        if ($this->mode === PricingMode::BillSplit) {
            return PricingMode::BillSplit->label();
        }

        $price = Number::format((float) $this->fixedUnitPrice, precision: 2, locale: 'fr');
        $cap = $this->capToActual ? 'plafonné au prix réel' : 'sans plafond';

        return "Prix fixé : {$price} FCFA/{$unit}, {$cap}";
    }
}
