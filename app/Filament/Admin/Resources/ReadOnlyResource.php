<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Ressource du panneau d'administration : consultation seule (UC-21, H-79).
 *
 * Les politiques de l'application (HousePolicy…) limitent chaque propriétaire à ses propres
 * données ; elles ne s'appliquent pas ici : seul un administrateur entre dans le panneau
 * (User::canAccessPanel) et il voit tous les comptes, sans rien pouvoir créer, modifier ni
 * supprimer.
 */
abstract class ReadOnlyResource extends Resource
{
    public static function can(string|UnitEnum $action, ?Model $record = null): bool
    {
        return in_array($action, ['viewAny', 'view'], true);
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }
}
