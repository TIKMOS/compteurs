<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Casts\DateOnly;
use App\Shared\Concerns\HasUlid;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un mois d'une maison (EF-13) : brouillon tant qu'il n'est pas clôturé (EF-31, EF-32).
 *
 * @property int $id
 * @property string $ulid
 * @property int $house_id
 * @property CarbonImmutable $month 1er du mois
 * @property PeriodStatus $status
 * @property CarbonImmutable|null $closed_at
 */
#[Fillable(['month', 'status'])]
final class BillingPeriod extends Model
{
    use HasUlid;

    /**
     * @return BelongsTo<House, $this>
     */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * @return HasMany<UtilityBill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(UtilityBill::class);
    }

    public function bill(Utility $utility): ?UtilityBill
    {
        return $this->bills->firstWhere('utility', $utility);
    }

    /**
     * Dernier jour du mois : date des relevés de fin de mois (H-49).
     */
    public function monthEnd(): CarbonImmutable
    {
        return $this->month->endOfMonth()->startOfDay();
    }

    public function isClosed(): bool
    {
        return $this->status === PeriodStatus::Closed;
    }

    /**
     * « novembre 2026 ».
     */
    public function label(): string
    {
        return $this->month->locale('fr')->isoFormat('MMMM YYYY');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => DateOnly::class,
            'status' => PeriodStatus::class,
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }
}
