<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ComplianceAcknowledgements;

use App\Filament\Admin\Resources\ComplianceAcknowledgements\Pages\ListComplianceAcknowledgements;
use App\Filament\Admin\Resources\ReadOnlyResource;
use App\Models\ComplianceAcknowledgement;
use App\Shared\Enums\AcknowledgementKind;
use App\Shared\Enums\Utility;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Confirmations de l'avertissement de conformité SBEE / SONEB (EF-49) : preuves conservées
 * telles quelles, consultables par l'administrateur, jamais modifiables.
 */
final class ComplianceAcknowledgementResource extends ReadOnlyResource
{
    protected static ?string $model = ComplianceAcknowledgement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'confirmations-conformite';

    protected static ?string $modelLabel = 'confirmation de conformité';

    protected static ?string $pluralModelLabel = 'confirmations de conformité';

    protected static ?int $navigationSort = 3;

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'house']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('acknowledged_at', 'desc')
            ->columns([
                TextColumn::make('acknowledged_at')->label('Confirmée le')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Propriétaire')->searchable(),
                TextColumn::make('house.name')->label('Maison')->searchable(),
                TextColumn::make('utility')->label('Fluide')
                    ->formatStateUsing(fn (Utility $state): string => Str::ucfirst($state->label())),
                TextColumn::make('kind')->label('Événement')
                    ->formatStateUsing(fn (AcknowledgementKind $state): string => $state->label()),
                TextColumn::make('notice_text')->label('Texte affiché')->wrap()->toggleable(),
                TextColumn::make('ip_address')->label('Adresse IP')->placeholder('—')->toggleable(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListComplianceAcknowledgements::route('/')];
    }
}
