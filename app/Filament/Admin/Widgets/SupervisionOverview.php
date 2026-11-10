<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\Unit;
use App\Models\User;
use App\Shared\Enums\PeriodStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Chiffres de supervision (UC-21) : volume de l'application, tous comptes confondus.
 */
final class SupervisionOverview extends StatsOverviewWidget
{
    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make('Comptes', User::query()->count()),
            Stat::make('Maisons', House::query()->count()),
            Stat::make('Logements', Unit::query()->count()),
            Stat::make('Mois clôturés', BillingPeriod::query()->where('status', PeriodStatus::Closed)->count()),
        ];
    }
}
