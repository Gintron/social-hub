<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources\Tables;

use App\Filament\Resources\Sources\SourceActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.name')->label('Brend')->sortable(),
                TextColumn::make('name')->label('Izvor')->searchable()->description(fn ($record): string => (string) $record->base_url),
                TextColumn::make('type')->label('Vrsta')->badge(),
                IconColumn::make('enabled')->label('Uključen')->boolean(),
                TextColumn::make('content_items_count')->label('Stavke')->counts('contentItems'),
                TextColumn::make('last_synced_at')->label('Sinkronizirano')->since()->placeholder('nikad'),
                TextColumn::make('last_error')->label('Greška')->limit(40)->color('danger')->placeholder('—'),
            ])
            ->recordActions([
                SourceActions::test(),
                SourceActions::syncNow(),
                EditAction::make(),
            ]);
    }
}
