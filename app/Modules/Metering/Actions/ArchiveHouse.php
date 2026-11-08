<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\House;
use App\Modules\Metering\Support\Journal;

/**
 * Archive une maison (UC-02) : elle disparaît des listes mais rien n'est supprimé,
 * ni ses compteurs, ni sa tarification, ni les confirmations de conformité (EF-49).
 */
final class ArchiveHouse
{
    public function execute(House $house): void
    {
        Journal::record('archived', "Maison « {$house->name} » archivée", $house);
        $house->delete();
    }
}
