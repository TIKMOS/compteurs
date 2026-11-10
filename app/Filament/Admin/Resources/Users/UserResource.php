<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users;

use App\Actions\SetAdministrator;
use App\Filament\Admin\Resources\ReadOnlyResource;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Comptes (UC-21) : liste de tous les comptes, et nomination ou retrait d'un administrateur.
 */
final class UserResource extends ReadOnlyResource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'compte';

    protected static ?string $pluralModelLabel = 'comptes';

    protected static ?int $navigationSort = 1;

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('houses');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('phone')->label('Téléphone')->searchable()->placeholder('—'),
                TextColumn::make('houses_count')->label('Maisons')->numeric()->sortable(),
                IconColumn::make('email_verified')->label('E-mail vérifié')->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->email_verified_at !== null),
                IconColumn::make('is_admin')->label('Administrateur')->boolean(),
                TextColumn::make('created_at')->label('Inscrit le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_admin')->label('Administrateur'),
            ])
            ->recordActions([
                Action::make('nommer')
                    ->label('Nommer administrateur')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (User $record): bool => ! $record->is_admin)
                    ->requiresConfirmation()
                    ->modalDescription('Ce compte verra tous les comptes, toutes les maisons et le journal d\'activité.')
                    ->action(fn (User $record) => app(SetAdministrator::class)->handle($record, true)),
                Action::make('retirer')
                    ->label('Retirer l\'administration')
                    ->color('danger')
                    // On ne se retire pas soi-même : il pourrait ne rester aucun administrateur.
                    ->visible(fn (User $record): bool => $record->is_admin && ! $record->is(auth()->user()))
                    ->requiresConfirmation()
                    ->action(fn (User $record) => app(SetAdministrator::class)->handle($record, false)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
