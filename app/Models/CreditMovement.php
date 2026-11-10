<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\CreditMovementKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement sur un lot de crédit : imputation sur une tranche, ou solde à la sortie (EF-24).
 *
 * @property int $id
 * @property int $consumption_credit_id
 * @property int $billing_period_id
 * @property CreditMovementKind $kind
 * @property string $quantity
 * @property string|null $value fraction exacte en FCFA, pour un solde
 * @property-read ConsumptionCredit $credit
 */
#[Fillable(['billing_period_id', 'kind', 'quantity', 'value'])]
final class CreditMovement extends Model
{
    /**
     * @return BelongsTo<ConsumptionCredit, $this>
     */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(ConsumptionCredit::class, 'consumption_credit_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CreditMovementKind::class,
            'quantity' => 'decimal:3',
        ];
    }
}
