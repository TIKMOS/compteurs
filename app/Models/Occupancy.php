<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Période pendant laquelle un locataire occupe un logement (EF-07), bornée par un relevé
 * d'entrée et, à la sortie, un relevé de sortie sur chaque décompteur actif (EF-08).
 *
 * @property int $id
 * @property int $unit_id
 * @property int $tenant_id
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable|null $ended_on
 */
#[Fillable(['unit_id', 'tenant_id', 'started_on', 'ended_on'])]
final class Occupancy extends Model
{
    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        // Un logement archivé garde son historique d'occupations.
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_on' => DateOnly::class,
            'ended_on' => DateOnly::class,
        ];
    }
}
