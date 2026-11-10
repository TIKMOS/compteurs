<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solde du crédit d'un locataire sortant sur sa dernière fiche (EF-24 f) : déduit de la fiche,
 * l'excédent est « à rembourser au locataire ».
 *
 * @property int $id
 * @property int $occupancy_id
 * @property int $billing_period_id
 * @property int $statement_total
 * @property int $credit_value
 * @property int $deducted_amount
 * @property int $refund_due
 * @property-read Occupancy $occupancy
 * @property-read BillingPeriod $billingPeriod
 */
#[Fillable(['occupancy_id', 'statement_total', 'credit_value', 'deducted_amount', 'refund_due'])]
final class CreditSettlement extends Model
{
    /**
     * @return BelongsTo<Occupancy, $this>
     */
    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    /**
     * @return BelongsTo<BillingPeriod, $this>
     */
    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    public function netDue(): int
    {
        return $this->statement_total - $this->deducted_amount;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statement_total' => 'integer',
            'credit_value' => 'integer',
            'deducted_amount' => 'integer',
            'refund_due' => 'integer',
            'refunded_at' => 'immutable_datetime',
        ];
    }
}
