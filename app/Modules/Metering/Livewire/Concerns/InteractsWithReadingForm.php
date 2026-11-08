<?php

declare(strict_types=1);

namespace App\Modules\Metering\Livewire\Concerns;

use App\Models\SubMeter;
use App\Models\Unit;
use App\Modules\Metering\Exceptions\OccupancyRejectedException;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Shared\Enums\Utility;
use App\Shared\Support\FrenchDecimal;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Date et index par décompteur d'un formulaire d'entrée ou de sortie (EF-08).
 */
trait InteractsWithReadingForm
{
    /** « 2026-11-15 » */
    public string $readOn = '';

    /** @var array<string, string> index saisi par fluide, « 1 626 » */
    public array $indexes = ['electricity' => '', 'water' => ''];

    /**
     * Dernier relevé connu de chaque décompteur actif, pour guider la saisie.
     *
     * @return array<string, string> « 1 392 kWh le 31/10/2026 »
     */
    public function lastReadings(): array
    {
        $hints = [];
        foreach (Utility::cases() as $utility) {
            $meter = $this->unit->activeSubMeter($utility);
            if ($meter instanceof SubMeter) {
                $chain = $meter->readingChain();
                $last = end($chain);
                $hints[$utility->value] = sprintf(
                    'Dernier relevé : %s %s le %s',
                    Milli::format($last->index),
                    $utility->unit(),
                    $last->readOn->format('d/m/Y'),
                );
            }
        }

        return $hints;
    }

    /**
     * @throws ValidationException
     */
    protected function validatedReadOn(string $label): CarbonImmutable
    {
        $this->validate(
            ['readOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
            ['readOn.before_or_equal' => "La {$label} ne peut pas être dans le futur."],
            ['readOn' => $label],
        );

        return CarbonImmutable::createFromFormat('!Y-m-d', $this->readOn);
    }

    /**
     * Index normalisés (« 1626.000 ») des décompteurs actifs du logement.
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    protected function validatedIndexes(Unit $unit): array
    {
        $values = [];
        $errors = [];

        foreach (Utility::cases() as $utility) {
            if ($unit->activeSubMeter($utility) === null) {
                continue;
            }

            $value = FrenchDecimal::parse($this->indexes[$utility->value] ?? '', 3);
            if ($value === null || strlen($value) > 13) {
                $errors["indexes.{$utility->value}"] = "Saisissez l'index affiché sur le décompteur d'{$utility->label()} (ex. 1 626 ou 199,5).";
            } else {
                $values[$utility->value] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * Traduit un refus métier en message sous le bon champ.
     *
     * @throws ValidationException
     */
    protected function rejectWith(OccupancyRejectedException|ReadingRejectedException|PeriodClosedException $e): never
    {
        $field = $e instanceof ReadingRejectedException ? "indexes.{$e->utility->value}" : 'readOn';

        throw ValidationException::withMessages([$field => $e->getMessage()]);
    }
}
