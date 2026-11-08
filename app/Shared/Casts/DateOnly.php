<?php

declare(strict_types=1);

namespace App\Shared\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Date sans heure, stockée « Y-m-d » sur MySQL comme sur SQLite.
 *
 * Le cast « date » de Laravel écrit « Y-m-d H:i:s » : SQLite garde alors l'heure et une
 * recherche sur « 2026-11-01 » ne trouve plus la ligne (doublons de périodes). Ce cast rend
 * les comparaisons de dates identiques sur les deux bases.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class DateOnly implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse((string) $value)->format('Y-m-d');
    }
}
