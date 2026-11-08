<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Concerns\HasUlid;
use App\Shared\Enums\Utility;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Logement d'une maison : chambre, appartement, boutique (EF-03). Au plus un décompteur
 * actif par fluide (EF-04) ; sans décompteur, il ne participe pas à la répartition (EF-05).
 *
 * @property int $id
 * @property string $ulid
 * @property int $house_id
 * @property string $label
 */
#[Fillable(['label'])]
final class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * Ordre naturel des libellés : « Chambre 2 » avant « Chambre 10 » (H-5).
     *
     * @param Collection<int, Unit> $units
     *
     * @return Collection<int, Unit>
     */
    public static function sortNaturally(Collection $units): Collection
    {
        return $units->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @return BelongsTo<House, $this>
     */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    /**
     * @return HasMany<SubMeter, $this>
     */
    public function subMeters(): HasMany
    {
        return $this->hasMany(SubMeter::class);
    }

    public function activeSubMeter(Utility $utility): ?SubMeter
    {
        return $this->subMeters->first(
            fn (SubMeter $meter): bool => $meter->utility === $utility && $meter->removed_on === null,
        );
    }
}
