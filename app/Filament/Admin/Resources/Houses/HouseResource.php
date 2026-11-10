<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Houses;

use App\Filament\Admin\Resources\Houses\Pages\ListHouses;
use App\Filament\Admin\Resources\ReadOnlyResource;
use App\Models\House;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Maisons de tous les propriétaires (UC-21), archivées comprises, en lecture seule.
 */
final class HouseResource extends ReadOnlyResource
{
    protected static ?string $model = House::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $modelLabel = 'maison';

    protected static ?string $pluralModelLabel = 'maisons';

    protected static ?int $navigationSort = 2;

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->with('owner')
            ->withCount(['units', 'billingPeriods']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Maison')->searchable()->sortable(),
                TextColumn::make('owner.name')->label('Propriétaire')->searchable(),
                TextColumn::make('address')->label('Adresse')->placeholder('—')->wrap(),
                TextColumn::make('units_count')->label('Logements')->numeric()->sortable(),
                TextColumn::make('billing_periods_count')->label('Mois saisis')->numeric()->sortable(),
                IconColumn::make('archived')->label('Archivée')->boolean()
                    ->getStateUsing(fn (House $record): bool => $record->trashed()),
                TextColumn::make('created_at')->label('Créée le')->dateTime('d/m/Y')->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListHouses::route('/')];
    }
}
