<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Activities\Pages;

use App\Filament\Admin\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\ListRecords;

final class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;
}
