<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Adds an immutable ULID column to a model.
 *
 * Convention :
 *   - `ulid` column (char(26), unique) auto-filled at `creating`
 *   - le modèle utilise `ulid` pour le public route binding
 */
trait HasUlid
{
    public static function bootHasUlid(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->getAttribute('ulid')) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
