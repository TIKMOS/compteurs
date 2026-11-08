<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Concerns\HasUlid;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Locataire d'un propriétaire (EF-06). Il peut occuper successivement ou en même temps
 * plusieurs logements : chaque occupation est distincte (H-28).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $name
 * @property string|null $phone « +2290197000000 »
 */
#[Fillable(['name', 'phone'])]
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
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
     * @return HasMany<Occupancy, $this>
     */
    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    public function hasCurrentOccupancy(): bool
    {
        return $this->occupancies()->whereNull('ended_on')->exists();
    }

    /**
     * « 01 97 00 00 00 » pour l'affichage.
     */
    public function displayPhone(): ?string
    {
        if ($this->phone === null) {
            return null;
        }

        return trim(chunk_split(substr($this->phone, 4), 2, ' '));
    }

    /**
     * @param Builder<Tenant> $query
     */
    public function scopeOwnedBy(Builder $query, User $owner): void
    {
        $query->where('user_id', $owner->id);
    }
}
