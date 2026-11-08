<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ChargeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participation figée d'un payeur (locataire ou propriétaire / vacant) d'un logement pour un
 * fluide et un mois (EF-32).
 *
 * @property int $id
 * @property int $utility_bill_id
 * @property int $unit_id
 * @property int|null $occupancy_id
 * @property ChargeKind $kind
 * @property string $raw_consumption
 * @property string $credit_applied
 * @property string $consumption
 * @property bool $is_estimated
 * @property bool $includes_regularization
 * @property string $theoretical_amount
 * @property int $rounding_adjustment
 * @property int $amount
 */
#[Fillable(['unit_id', 'occupancy_id', 'kind', 'raw_consumption', 'credit_applied', 'consumption', 'is_estimated', 'includes_regularization', 'theoretical_amount', 'rounding_adjustment', 'amount'])]
final class Charge extends Model
{
    /**
     * @return BelongsTo<UtilityBill, $this>
     */
    public function utilityBill(): BelongsTo
    {
        return $this->belongsTo(UtilityBill::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Occupancy, $this>
     */
    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ChargeKind::class,
            'raw_consumption' => 'decimal:3',
            'credit_applied' => 'decimal:3',
            'consumption' => 'decimal:3',
            'is_estimated' => 'boolean',
            'includes_regularization' => 'boolean',
            'rounding_adjustment' => 'integer',
            'amount' => 'integer',
        ];
    }
}
