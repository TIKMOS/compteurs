<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot de crédit de consommation (EF-24 b) : appartient au payeur de la tranche surestimée,
 * valorisé au prix appliqué du mois surestimé (D-20). Reste = quantité − Σ mouvements.
 *
 * @property int $id
 * @property int|null $occupancy_id null = propriétaire / vacant (D-13)
 * @property int $unit_id
 * @property Utility $utility
 * @property int $origin_billing_period_id
 * @property int $created_in_billing_period_id
 * @property string $quantity
 * @property string $unit_price fraction exacte
 * @property-read Occupancy|null $occupancy null : crédit du propriétaire
 * @property-read BillingPeriod $originPeriod
 * @property-read BillingPeriod $createdInPeriod
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CreditMovement> $movements
 */
#[Fillable(['occupancy_id', 'unit_id', 'utility', 'origin_billing_period_id', 'created_in_billing_period_id', 'quantity', 'unit_price'])]
final class ConsumptionCredit extends Model
{
    /**
     * @return HasMany<CreditMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CreditMovement::class);
    }

    /**
     * Payeur du lot ; null = propriétaire / logement vacant.
     *
     * @return BelongsTo<Occupancy, $this>
     */
    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    /**
     * @return BelongsTo<BillingPeriod, $this>
     */
    public function originPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class, 'origin_billing_period_id');
    }

    /**
     * @return BelongsTo<BillingPeriod, $this>
     */
    public function createdInPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class, 'created_in_billing_period_id');
    }

    /**
     * Reste du lot en millièmes.
     */
    public function remaining(): int
    {
        $used = $this->movements->sum(fn (CreditMovement $m): int => Milli::fromDecimal($m->quantity));

        return Milli::fromDecimal($this->quantity) - $used;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
            'quantity' => 'decimal:3',
        ];
    }
}
