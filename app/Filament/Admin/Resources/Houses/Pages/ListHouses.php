<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Houses\Pages;

use App\Filament\Admin\Resources\Houses\HouseResource;
use Filament\Resources\Pages\ListRecords;

final class ListHouses extends ListRecords
{
    protected static string $resource = HouseResource::class;
}
