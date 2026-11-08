<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\House;
use App\Models\Unit;
use App\Modules\Metering\Data\SubMeterInstallation;
use App\Shared\Enums\Utility;
use Illuminate\Support\Facades\DB;

/**
 * Crée un logement et, s'il en a, ses décompteurs (UC-03, EF-03, EF-04).
 */
final class CreateUnit
{
    public function __construct(private readonly InstallSubMeter $installSubMeter) {}

    /**
     * @param array<value-of<Utility>, SubMeterInstallation|null> $subMeters null = pas de décompteur (EF-05)
     */
    public function execute(House $house, string $label, array $subMeters = []): Unit
    {
        return DB::transaction(function () use ($house, $label, $subMeters): Unit {
            $unit = $house->units()->create(['label' => trim($label)]);

            foreach (Utility::cases() as $utility) {
                $installation = $subMeters[$utility->value] ?? null;
                if ($installation !== null) {
                    $this->installSubMeter->execute($unit, $utility, $installation);
                }
            }

            return $unit->load('subMeters');
        });
    }
}
