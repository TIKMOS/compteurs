<?php

declare(strict_types=1);

namespace App\Modules\Metering\Livewire\Concerns;

use App\Models\House;
use App\Modules\Metering\Data\PricingRule;
use App\Shared\Enums\AcknowledgementKind;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\Utility;
use App\Shared\Support\FrenchDecimal;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Champs « mode de calcul » d'un formulaire Livewire (création de maison, paramètres),
 * et état de la fenêtre d'avertissement de conformité (EF-48).
 */
trait InteractsWithPricingForm
{
    /** @var array<string, string> mode par fluide */
    public array $modes = ['electricity' => 'bill_split', 'water' => 'bill_split'];

    /** @var array<string, string> prix fixé saisi, « 127,50 » */
    public array $prices = ['electricity' => '', 'water' => ''];

    /** @var array<string, bool> plafond au prix réel, activé par défaut */
    public array $caps = ['electricity' => true, 'water' => true];

    public bool $confirmingCompliance = false;

    /** @var list<string> événements à confirmer, en français */
    public array $complianceEvents = [];

    public function cancelCompliance(): void
    {
        $this->confirmingCompliance = false;
        $this->complianceEvents = [];
    }

    protected function fillPricingFrom(House $house): void
    {
        foreach (Utility::cases() as $utility) {
            $rule = $house->currentPricing($utility)?->rule() ?? PricingRule::billSplit();
            $this->modes[$utility->value] = $rule->mode->value;
            $this->prices[$utility->value] = $rule->fixedUnitPrice === null
                ? ''
                : Number::format((float) $rule->fixedUnitPrice, precision: 2, locale: 'fr');
            $this->caps[$utility->value] = $rule->capToActual;
        }
    }

    /**
     * Construit la règle d'un fluide à partir des champs, ou lève une erreur de validation
     * sur le prix (« prices.water »).
     *
     * @throws ValidationException
     */
    protected function pricingRuleFor(Utility $utility): PricingRule
    {
        $key = $utility->value;

        if (($this->modes[$key] ?? '') !== PricingMode::FixedPrice->value) {
            return PricingRule::billSplit();
        }

        $price = FrenchDecimal::parse($this->prices[$key] ?? '');
        if ($price === null || $price === '0.00' || strlen($price) > 11) {
            throw ValidationException::withMessages([
                "prices.{$key}" => "Saisissez un prix positif en FCFA par {$utility->unit()}, avec 2 décimales au plus (ex. 127,50).",
            ]);
        }

        return PricingRule::fixed($price, (bool) ($this->caps[$key] ?? true));
    }

    /**
     * @param list<AcknowledgementKind> $kinds
     */
    protected function askComplianceConfirmation(Utility $utility, array $kinds): void
    {
        foreach ($kinds as $kind) {
            $this->complianceEvents[] = "{$kind->label()} ({$utility->label()})";
        }

        $this->confirmingCompliance = true;
    }
}
