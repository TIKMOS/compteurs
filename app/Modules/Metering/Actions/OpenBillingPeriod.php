<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\House;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Shared\Enums\PeriodStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Renvoie la période du mois d'une date, en l'ouvrant si besoin (UC-07, EF-12, EF-13).
 * Refuse d'écrire dans un mois clôturé.
 */
final class OpenBillingPeriod
{
    /**
     * @throws PeriodClosedException
     */
    public function execute(House $house, CarbonInterface $date): BillingPeriod
    {
        $month = CarbonImmutable::instance($date)->startOfMonth();

        $period = $house->billingPeriods()->firstOrCreate(
            ['month' => $month->toDateString()],
            ['status' => PeriodStatus::Draft],
        );

        if ($period->isClosed()) {
            throw new PeriodClosedException($period);
        }

        return $period;
    }
}
