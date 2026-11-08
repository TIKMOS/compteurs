<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\Utility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture SBEE ou SONEB d'un mois (EF-19, EF-20). Les champs de tarification sont figés à
 * la clôture (EF-32, tranche 5).
 *
 * @property int $id
 * @property int $billing_period_id
 * @property int $main_meter_id
 * @property Utility $utility
 * @property int|null $total_amount FCFA TTC
 * @property string|null $main_consumption « 490.000 », information seulement
 */
#[Fillable(['main_meter_id', 'utility', 'total_amount', 'main_consumption'])]
final class UtilityBill extends Model
{
    /**
     * @return BelongsTo<BillingPeriod, $this>
     */
    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    /**
     * @return BelongsTo<MainMeter, $this>
     */
    public function mainMeter(): BelongsTo
    {
        return $this->belongsTo(MainMeter::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
            'total_amount' => 'integer',
            'main_consumption' => 'decimal:3',
            'cap_to_actual' => 'boolean',
            'fixed_unit_price' => 'decimal:2',
            'total_consumption' => 'decimal:3',
        ];
    }
}
