<?php

declare(strict_types=1);

namespace App\Modules\Metering\Livewire\Concerns;

use App\Models\SubMeter;
use App\Modules\Metering\Data\SubMeterInstallation;
use App\Shared\Enums\Utility;
use App\Shared\Support\FrenchDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Champs « décompteur » d'un formulaire Livewire, indexés par fluide : numéro de série,
 * date et index de pose (EF-04). L'index se saisit à la française (« 1 250,5 »).
 */
trait InteractsWithSubMeterForm
{
    /** @var array<string, string> */
    public array $serials = ['electricity' => '', 'water' => ''];

    /** @var array<string, string> date de pose, « 2026-11-08 » */
    public array $installedOn = ['electricity' => '', 'water' => ''];

    /** @var array<string, string> index de pose saisi */
    public array $installIndexes = ['electricity' => '', 'water' => ''];

    protected function resetSubMeterFields(Utility $utility): void
    {
        $this->serials[$utility->value] = '';
        $this->installedOn[$utility->value] = CarbonImmutable::now()->toDateString();
        $this->installIndexes[$utility->value] = '';
    }

    protected function fillSubMeterFields(SubMeter $meter): void
    {
        $key = $meter->utility->value;
        $this->serials[$key] = (string) $meter->serial_number;
        $this->installedOn[$key] = $meter->installed_on->toDateString();
        $this->installIndexes[$key] = SubMeter::formatIndex($meter->install_index);
    }

    /**
     * @throws ValidationException
     */
    protected function subMeterInstallationFor(Utility $utility): SubMeterInstallation
    {
        $key = $utility->value;

        $this->validate([
            "serials.{$key}" => ['nullable', 'string', 'max:60'],
            "installedOn.{$key}" => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            "installedOn.{$key}.before_or_equal" => 'La date de pose ne peut pas être dans le futur.',
        ], [
            "serials.{$key}" => 'numéro de série',
            "installedOn.{$key}" => 'date de pose',
        ]);

        // DECIMAL(12,3) : 9 chiffres avant la virgule au plus.
        $index = FrenchDecimal::parse($this->installIndexes[$key] ?? '', 3);
        if ($index === null || strlen($index) > 13) {
            throw ValidationException::withMessages([
                "installIndexes.{$key}" => "Saisissez l'index affiché sur le décompteur, en {$utility->unit()} (ex. 1 250 ou 412,5).",
            ]);
        }

        return new SubMeterInstallation(
            installedOn: CarbonImmutable::createFromFormat('!Y-m-d', $this->installedOn[$key]),
            installIndex: $index,
            serialNumber: $this->serials[$key] ?? null,
        );
    }
}
