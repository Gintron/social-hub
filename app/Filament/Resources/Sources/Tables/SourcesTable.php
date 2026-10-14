<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources\Tables;

use App\Filament\Resources\Sources\SourceActions;
use App\Models\Source;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                // The brand sits under the name, as in the other lists, so a phone has room for what matters.
                TextColumn::make('name')->label('Izvor')->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('base_url', 'like', "%{$search}%")
                            ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$search}%")),
                    ))
                    ->description(fn (Source $record): string => collect([$record->brand?->name, parse_url((string) $record->base_url, PHP_URL_HOST) ?: null])->filter()->implode(' · ')),
                TextColumn::make('type')->label('Vrsta')->badge()->visibleFrom('xl'),
                IconColumn::make('enabled')->label('Uključen')->boolean(),
                TextColumn::make('content_items_count')->label('Stavke')->counts('contentItems')->visibleFrom('xl'),
                TextColumn::make('last_synced_at')->label('Sinkronizirano')->since()->placeholder('nikad')->visibleFrom('lg'),
                TextColumn::make('last_error')->label('Greška')->limit(40)->color('danger')->placeholder('—')->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brend')->relationship('brand', 'name'),
            ])
            ->recordActions([
                SourceActions::test()->iconButton()->tooltip('Testiraj vezu'),
                SourceActions::syncNow()->iconButton()->tooltip('Sinkroniziraj sada'),
                EditAction::make()->iconButton()->tooltip('Uredi izvor'),
            ]);
    }
}
