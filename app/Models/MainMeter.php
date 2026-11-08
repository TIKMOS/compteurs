<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\Utility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur principal du fournisseur (SBEE ou SONEB), un par fluide et par maison (EF-02).
 *
 * @property int $id
 * @property int $house_id
 * @property Utility $utility
 * @property string|null $provider_reference
 */
#[Fillable(['utility', 'provider_reference'])]
final class MainMeter extends Model
{
    /**
     * @return BelongsTo<House, $this>
     */
    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function providerName(): string
    {
        return match ($this->utility) {
            Utility::Electricity => 'SBEE',
            Utility::Water => 'SONEB',
        };
    }

    public function referenceLabel(): string
    {
        return match ($this->utility) {
            Utility::Electricity => 'N° de compteur SBEE',
            Utility::Water => 'N° de police SONEB',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
        ];
    }
}
