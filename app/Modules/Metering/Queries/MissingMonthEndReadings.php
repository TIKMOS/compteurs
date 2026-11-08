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
        $month = $month->startOfMonth();
        $result = [];

        foreach (House::query()->ownedBy($owner)->orderBy('name')->get() as $house) {
            $period = $house->billingPeriods()->where('month', $month->toDateString())->first();
            if ($period?->status === PeriodStatus::Closed) {
                continue;
            }

            $meters = SubMeter::query()
                ->whereHas('unit', fn ($q) => $q->where('house_id', $house->id))
                ->get()
                ->filter(fn (SubMeter $m): bool => $m->needsMonthEndReading($month));

            $done = $period === null ? 0 : $period->readings()
                ->where('kind', ReadingKind::MonthEnd)
                ->whereIn('sub_meter_id', $meters->pluck('id'))
                ->count();

            $missing = $meters->count() - $done;
            if ($missing > 0) {
                $result[] = ['house' => $house, 'missing' => $missing];
            }
        }

        return $result;
    }
}
