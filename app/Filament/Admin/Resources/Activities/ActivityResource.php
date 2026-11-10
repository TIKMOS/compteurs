<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Activities;

use App\Filament\Admin\Resources\Activities\Pages\ListActivities;
use App\Filament\Admin\Resources\ReadOnlyResource;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Journal d'activité de tous les comptes (EF-35, H-58) : qui a fait quoi, et quand.
 */
final class ActivityResource extends ReadOnlyResource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'journal';

    protected static ?string $navigationLabel = 'Journal d\'activité';

    protected static ?string $modelLabel = 'action';

    protected static ?string $pluralModelLabel = 'journal d\'activité';

    protected static ?int $navigationSort = 4;

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('causer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('causer.name')->label('Auteur')->placeholder('Système'),
                TextColumn::make('event')->label('Événement')->badge()->searchable(),
                TextColumn::make('description')->label('Description')->wrap()->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivities::route('/')];
    }
}
