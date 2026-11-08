<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Concerns\HasUlid;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\HouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Maison d'un propriétaire (EF-01) : deux compteurs principaux et, par fluide,
 * un historique de tarification (EF-39, EF-46).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $name
 * @property string|null $address
 */
#[Fillable(['name', 'address'])]
final class House extends Model
{
    /** @use HasFactory<HouseFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<MainMeter, $this>
     */
    public function mainMeters(): HasMany
    {
        return $this->hasMany(MainMeter::class);
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @return HasMany<UtilityPricing, $this>
     */
    public function pricings(): HasMany
    {
        return $this->hasMany(UtilityPricing::class);
    }

    /**
     * @return HasMany<ComplianceAcknowledgement, $this>
     */
    public function complianceAcknowledgements(): HasMany
    {
        return $this->hasMany(ComplianceAcknowledgement::class);
    }

    public function mainMeter(Utility $utility): ?MainMeter
    {
        return $this->mainMeters->firstWhere('utility', $utility);
    }

    /**
     * Version de tarification applicable à un mois : la plus récente dont le mois d'effet
     * est ≤ ce mois ; à défaut, la plus ancienne (EF-46, D-24).
     */
    public function pricingFor(Utility $utility, CarbonInterface $month): ?UtilityPricing
    {
        $month = CarbonImmutable::instance($month)->startOfMonth();
        $versions = $this->pricings
            ->filter(fn (UtilityPricing $p): bool => $p->utility === $utility)
            ->sortBy(fn (UtilityPricing $p): string => $p->effective_from->toDateString());

        return $versions->last(fn (UtilityPricing $p): bool => $p->effective_from->lessThanOrEqualTo($month))
            ?? $versions->first();
    }

    public function currentPricing(Utility $utility): ?UtilityPricing
    {
        return $this->pricingFor($utility, CarbonImmutable::now());
    }

    /**
     * Mois de la dernière période clôturée.
     *
     * Les périodes (billing_periods) arrivent avec la tranche 4 du lot 1 : d'ici là, aucune
     * période n'est clôturée.
     */
    public function lastClosedMonth(): ?CarbonImmutable
    {
        return null;
    }

    /**
     * Premier mois non clôturé : mois d'effet proposé par défaut pour un changement de prix (D-24).
     */
    public function firstOpenMonth(): CarbonImmutable
    {
        return $this->lastClosedMonth()?->addMonthNoOverflow()
            ?? CarbonImmutable::now()->startOfMonth();
    }

    /**
     * @param Builder<House> $query
     */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('user_id', $owner->id);
    }
}
