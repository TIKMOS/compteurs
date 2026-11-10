<?php

declare(strict_types=1);

namespace App\Modules\Metering\Queries;

use App\Models\House;
use App\Models\SubMeter;
use App\Models\User;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\ReadingKind;
use Carbon\CarbonImmutable;

/**
 * Relevés de fin de mois encore à faire pour un propriétaire et un mois (UC-20) : par maison
 * non archivée, nombre de décompteurs en place sans relevé de fin de mois (réel ou estimé).
 */
final class MissingMonthEndReadings
{
    /**
     * @return list<array{house: House, missing: int}>
     */
    public function forOwner(User $owner, CarbonImmutable $month): array
    {
        $result = [];

        foreach (House::query()->ownedBy($owner)->orderBy('name')->get() as $house) {
            array_push($result, ...$this->forHouse($house, $month));
        }

        return $result;
    }

    /**
     * @return list<array{house: House, missing: int}> vide si rien ne manque
     */
    public function forHouse(House $house, CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $period = $house->billingPeriods()->where('month', $month->toDateString())->first();
        if ($period?->status === PeriodStatus::Closed) {
            return [];
        }

        $meters = SubMeter::query()
            ->whereHas('unit', fn ($q) => $q->whereNull('deleted_at')->where('house_id', $house->id))
            ->get()
            ->filter(fn (SubMeter $m): bool => $m->needsMonthEndReading($month));

        $done = $period === null ? 0 : $period->readings()
            ->where('kind', ReadingKind::MonthEnd)
            ->whereIn('sub_meter_id', $meters->pluck('id'))
            ->count();

        $missing = $meters->count() - $done;

        return $missing > 0 ? [['house' => $house, 'missing' => $missing]] : [];
    }
}
