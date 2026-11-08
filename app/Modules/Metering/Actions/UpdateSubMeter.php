<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\SubMeter;
use App\Modules\Metering\Data\SubMeterInstallation;
use App\Modules\Metering\Queries\ComputeBillingPeriod;
use App\Modules\Metering\Support\Journal;
use LogicException;

/**
 * Corrige un décompteur : le numéro de série toujours ; la date et l'index de pose
 * seulement tant qu'aucun relevé ne s'appuie dessus (sinon la chaîne des index, EF-17,
 * deviendrait incohérente).
 */
final class UpdateSubMeter
{
    public function execute(SubMeter $meter, SubMeterInstallation $installation): SubMeter
    {
        $attributes = ['serial_number' => filled($installation->serialNumber) ? trim($installation->serialNumber) : null];

        $installationChanged = ! $meter->installed_on->equalTo($installation->installedOn)
            || $meter->install_index !== $installation->installIndex;

        if ($installationChanged) {
            if (! $meter->installationIsEditable()) {
                throw new LogicException('La pose de ce décompteur sert déjà à des relevés : elle ne peut plus être modifiée.');
            }

            $attributes['installed_on'] = $installation->installedOn;
            $attributes['install_index'] = $installation->installIndex;
        }

        $meter->update($attributes);

        Journal::record('updated', sprintf(
            'Décompteur %s de « %s » corrigé',
            ComputeBillingPeriod::of($meter->utility->label()),
            $meter->unit()->withTrashed()->value('label'),
        ), $meter, $attributes);

        return $meter;
    }
}
