<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\Unit;

/**
 * Change le libellé d'un logement (UC-03). L'unicité dans la maison est validée par l'écran
 * et garantie par la clé unique (house_id, label).
 */
final class RenameUnit
{
    public function execute(Unit $unit, string $label): Unit
    {
        $unit->update(['label' => trim($label)]);

        return $unit;
    }
}
