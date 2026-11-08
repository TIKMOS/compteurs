<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Metering\Data\Reading;
use App\Shared\Casts\DateOnly;
use App\Shared\Enums\ReadingKind;
use App\Shared\Enums\Utility;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Décompteur privé d'un logement pour un fluide (EF-04). Un remplacement le dépose
 * (removed_on, removal_index) et en pose un nouveau qui le référence (EF-18, tranche 4).
 *
 * @property int $id
 * @property int $unit_id
 * @property Utility $utility
 * @property string|null $serial_number
 * @property CarbonImmutable $installed_on
 * @property string $install_index décimal « 1250.000 »
 * @property CarbonImmutable|null $removed_on
 * @property string|null $removal_index
 * @property int|null $replaces_sub_meter_id
 */
#[Fillable(['utility', 'serial_number', 'installed_on', 'install_index', 'replaces_sub_meter_id'])]
final class SubMeter extends Model
{
    /**
     * Index affiché à la française, sans zéros inutiles : « 1 250,5 ».
     */
    public static function formatIndex(string $index): string
    {
        return Milli::format(Milli::fromDecimal($index));
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isActive(): bool
    {
        return $this->removed_on === null;
    }

    /**
     * Le décompteur doit-il avoir un relevé de fin de mois pour cette période (EF-31) ?
     * Oui s'il est posé au plus tard le dernier jour du mois et n'a pas été déposé avant.
     */
    public function needsMonthEndReading(BillingPeriod|CarbonImmutable $month): bool
    {
        $monthEnd = $month instanceof BillingPeriod ? $month->monthEnd() : $month->endOfMonth()->startOfDay();

        return $this->installed_on->lessThanOrEqualTo($monthEnd)
            && ($this->removed_on === null || $this->removed_on->isAfter($monthEnd));
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * La pose (date, index) se corrige tant qu'aucun relevé ne s'appuie dessus (H-44).
     */
    public function installationIsEditable(): bool
    {
        return ! $this->readings()->exists();
    }

    /**
     * Chaîne des relevés du décompteur, pose comprise, dans l'ordre d'EF-11.
     *
     * @return list<Reading>
     */
    public function readingChain(?int $exceptReadingId = null): array
    {
        $chain = [new Reading(ReadingKind::Installation, $this->installed_on, Milli::fromDecimal($this->install_index))];

        $readings = $this->readings()->when($exceptReadingId !== null, fn ($q) => $q->whereKeyNot($exceptReadingId))->get();

        foreach ($readings as $reading) {
            $chain[] = $reading->toReading();
        }

        usort($chain, fn (Reading $a, Reading $b): int => strcmp($a->sortKey(), $b->sortKey()));

        return $chain;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
            'installed_on' => DateOnly::class,
            'install_index' => 'decimal:3',
            'removed_on' => DateOnly::class,
            'removal_index' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
