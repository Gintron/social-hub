<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')->label('')->disk('public')->circular(),
                TextColumn::make('name')->label('Brend')->searchable()->sortable()->description(fn ($record): string => $record->slug),
                TextColumn::make('site_url')->label('Web')->url(fn ($record): ?string => $record->site_url, shouldOpenInNewTab: true),
                TextColumn::make('sources_count')->label('Izvori')->counts('sources'),
                TextColumn::make('social_accounts_count')->label('Računi')->counts('socialAccounts'),
                TextColumn::make('content_items_count')->label('Stavke')->counts('contentItems'),
                TextColumn::make('post_drafts_count')->label('Nacrti')->counts('postDrafts'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
