<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

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
        $decimals = strlen(rtrim(substr(strrchr($index, '.') ?: '', 1), '0'));

        return Number::format((float) $index, precision: $decimals, locale: 'fr');
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
     * La pose (date, index) se corrige tant qu'aucun relevé ne s'appuie dessus.
     * Les relevés arrivent avec la tranche 4 : d'ici là, toujours vrai.
     */
    public function installationIsEditable(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
            'installed_on' => 'immutable_date',
            'install_index' => 'decimal:3',
            'removed_on' => 'immutable_date',
            'removal_index' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
