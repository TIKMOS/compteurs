<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Metering\Data\PricingRule;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Version de tarification d'un fluide pour une maison, à partir d'un mois d'effet (EF-46).
 *
 * @property int $id
 * @property int $house_id
 * @property Utility $utility
 * @property PricingMode $mode
 * @property string|null $fixed_unit_price décimal « 127.50 »
 * @property bool $cap_to_actual
 * @property CarbonImmutable $effective_from
 * @property int $created_by
 */
#[Fillable(['utility', 'mode', 'fixed_unit_price', 'cap_to_actual', 'effective_from', 'created_by'])]
final class UtilityPricing extends Model
{
    /**
     * @return BelongsTo<House, $this>
     */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ComplianceAcknowledgement, $this>
     */
    public function complianceAcknowledgements(): HasMany
    {
        return $this->hasMany(ComplianceAcknowledgement::class);
    }

    public function rule(): PricingRule
    {
        return new PricingRule($this->mode, $this->fixed_unit_price, $this->cap_to_actual);
    }

    /**
     * Une version utilisée par une période clôturée est immuable (H-34).
     * Les périodes arrivent avec la tranche 4 : d'ici là, aucune version n'est utilisée.
     */
    public function isUsedByClosedPeriod(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
            'mode' => PricingMode::class,
            'fixed_unit_price' => 'decimal:2',
            'cap_to_actual' => 'boolean',
            'effective_from' => 'immutable_date',
        ];
    }
}
