<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Casts\DateOnly;
use App\Shared\Concerns\HasUlid;
use App\Shared\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement reçu d'un locataire pour sa participation d'un mois (UC-18).
 *
 * @property int $id
 * @property string $ulid
 * @property int $occupancy_id
 * @property int $billing_period_id
 * @property int $amount FCFA
 * @property CarbonImmutable $paid_on
 * @property PaymentMethod $method
 * @property string|null $note
 */
#[Fillable(['occupancy_id', 'amount', 'paid_on', 'method', 'note', 'recorded_by'])]
final class Payment extends Model
{
    use HasUlid;

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => DateOnly::class,
            'method' => PaymentMethod::class,
        ];
    }
}
