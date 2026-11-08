<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\SubMeter;
use App\Models\Unit;
use App\Modules\Metering\Data\SubMeterInstallation;
use App\Modules\Metering\Exceptions\ActiveSubMeterExistsException;
use App\Shared\Enums\Utility;
use Illuminate\Support\Facades\DB;

/**
 * Pose un décompteur sur un logement qui n'en a pas pour ce fluide (UC-03, EF-04).
 * Le changement d'un décompteur existant passe par un remplacement (EF-18, tranche 4).
 */
final class InstallSubMeter
{
    /**
     * @throws ActiveSubMeterExistsException
     */
    public function execute(Unit $unit, Utility $utility, SubMeterInstallation $installation): SubMeter
    {
        return DB::transaction(function () use ($unit, $utility, $installation): SubMeter {
            $exists = $unit->subMeters()->where('utility', $utility)->whereNull('removed_on')->lockForUpdate()->exists();
            if ($exists) {
                throw new ActiveSubMeterExistsException($utility);
            }

            $meter = $unit->subMeters()->create([
                'utility' => $utility,
                'serial_number' => filled($installation->serialNumber) ? trim($installation->serialNumber) : null,
                'installed_on' => $installation->installedOn,
                'install_index' => $installation->installIndex,
            ]);
            $unit->unsetRelation('subMeters');

            return $meter;
        });
    }
}
