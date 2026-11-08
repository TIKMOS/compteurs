<?php

declare(strict_types=1);

namespace App\Modules\Metering\Actions;

use App\Models\BillingPeriod;
use App\Models\MeterReading;
use App\Models\SubMeter;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Modules\Metering\Exceptions\ReadingRejectedException;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Milli;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Enregistre (ou efface) l'index de fin de mois d'un décompteur (UC-08, EF-14, EF-16, EF-17,
 * EF-21). Saisir l'index réel remplace une estimation du même mois (T-18). Le relevé est daté
 * du dernier jour du mois (H-49).
 */
final class RecordMonthEndReading
{
    public function __construct(private readonly AssertReadingFitsChain $assertReading) {}

    /**
     * @throws PeriodClosedException
     */
    public static function assertWritable(BillingPeriod $period, SubMeter $meter): void
    {
        if ($period->isClosed()) {
            throw new PeriodClosedException($period);
        }

        if ($meter->unit()->withTrashed()->value('house_id') !== $period->house_id) {
            throw new LogicException('Ce décompteur n\'appartient pas à la maison de la période.');
        }

        if (! $meter->needsMonthEndReading($period)) {
            throw new LogicException('Ce décompteur n\'est pas en place à la fin de ce mois.');
        }
    }

    /**
     * @param string|null $index décimal « 1392.000 », ou null pour effacer la saisie
     *
     * @throws PeriodClosedException|ReadingRejectedException
     */
    public function execute(BillingPeriod $period, SubMeter $meter, ?string $index): ?MeterReading
    {
        return DB::transaction(function () use ($period, $meter, $index): ?MeterReading {
            $period = BillingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            self::assertWritable($period, $meter);

            $existing = $meter->readings()
                ->where('billing_period_id', $period->id)
                ->where('kind', ReadingKind::MonthEnd)
                ->first();

            if ($index === null) {
                $existing?->delete();

                return null;
            }

            $milli = Milli::fromDecimal($index);
            $this->assertReading->assert($meter, ReadingKind::MonthEnd, $period->monthEnd(), $milli, $existing?->id);

            $attributes = [
                'index_value' => Milli::toDecimal($milli),
                'is_estimated' => false,
                'estimation_basis' => null,
            ];

            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }

            return $meter->readings()->create([
                ...$attributes,
                'billing_period_id' => $period->id,
                'kind' => ReadingKind::MonthEnd,
                'read_on' => $period->monthEnd(),
            ]);
        });
    }
}
