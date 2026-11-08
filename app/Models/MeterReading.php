<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Metering\Data\Reading;
use App\Shared\Casts\DateOnly;
use App\Shared\Enums\ReadingKind;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relevé d'un décompteur : index lu ou estimé à une date (CONCEPTION.md § 4.2).
 *
 * @property int $id
 * @property int $sub_meter_id
 * @property int $billing_period_id
 * @property int|null $occupancy_id
 * @property ReadingKind $kind
 * @property CarbonImmutable $read_on
 * @property string $index_value « 1534.500 »
 * @property bool $is_estimated
 * @property string|null $estimation_basis
 */
#[Fillable(['sub_meter_id', 'billing_period_id', 'occupancy_id', 'kind', 'read_on', 'index_value', 'is_estimated', 'estimation_basis'])]
final class MeterReading extends Model
{
    /**
     * @return BelongsTo<SubMeter, $this>
     */
    public function subMeter(): BelongsTo
    {
        return $this->belongsTo(SubMeter::class);
    }

    /**
     * @return BelongsTo<BillingPeriod, $this>
     */
    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    /**
     * @return BelongsTo<Occupancy, $this>
     */
    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    /**
     * Relevé du cœur de calcul (index en millièmes).
     */
    public function toReading(): Reading
    {
        return new Reading(
            kind: $this->kind,
            readOn: $this->read_on,
            index: Milli::fromDecimal($this->index_value),
            isEstimated: $this->is_estimated,
            occupancyId: $this->occupancy_id,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReadingKind::class,
            'read_on' => DateOnly::class,
            'index_value' => 'decimal:3',
            'is_estimated' => 'boolean',
        ];
    }
}
