<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AcknowledgementKind;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Confirmation de l'avertissement de conformité SBEE / SONEB (EF-48, EF-49) : preuve
 * conservée telle quelle, jamais modifiée ni supprimée.
 *
 * @property int $id
 * @property int $user_id
 * @property int $house_id
 * @property int $utility_pricing_id
 * @property Utility $utility
 * @property AcknowledgementKind $kind
 * @property string $notice_text
 * @property CarbonImmutable $acknowledged_at
 * @property string|null $ip_address
 */
#[Fillable(['user_id', 'house_id', 'utility_pricing_id', 'utility', 'kind', 'notice_text', 'acknowledged_at', 'ip_address'])]
final class ComplianceAcknowledgement extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Une confirmation de conformité ne peut pas être modifiée.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Une confirmation de conformité ne peut pas être supprimée.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UtilityPricing, $this>
     */
    public function pricing(): BelongsTo
    {
        return $this->belongsTo(UtilityPricing::class, 'utility_pricing_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utility' => Utility::class,
            'kind' => AcknowledgementKind::class,
            'acknowledged_at' => 'immutable_datetime',
        ];
    }
}
